<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\NormalizesLocalizedSlugs;
use App\Http\Controllers\Controller;
use App\Jobs\DispatchAiFieldGenerationJobs;
use App\Jobs\ExtractProductGistJob;
use App\Jobs\GenerateAiDescriptionsBatchJob;
use App\Models\Category;
use App\Models\Language;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductDescription;
use App\Models\User;
use App\Support\AiGenerationErrorReason;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\PreserveText;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Админка: один товар = строка {@see Product}, переводимые тексты — {@see ProductDescription}.
 *
 * Поток данных (упрощённо):
 * - Сырьё AI: колонки `products.source_text1` … `source_text8`. На форме — textarea; в коде сборка через
 *   {@see Product::combineSourceSegments} → один большой текст с тегами &lt;source id="N"&gt;.
 * - Выжимка: `products.result` + `result_source_sha1` (пишет {@see ExtractProductGistJob}).
 * - AI-тексты по языкам: колонки `product_descriptions.ai_*`; модели этапа 1 — `product_descriptions.ai_*_model`.
 * - Запуск генерации по кнопке: {@see generateAi} → очередь (Bus::chain: выжимка → batch description → поля этапов 2–3).
 *
 * Основные маршруты: см. routes/web.php (generate_ai, check_ai_status, update_description_models, extract_text).
 */
class ProductController extends Controller
{
    /** Нормализация slug для name_* полей вкладок языков ({@see NormalizesLocalizedSlugs}). */
    use NormalizesLocalizedSlugs;

    /**
     * Правила валидации для восьми полей сырья (имена ключей совпадают с {@see Product::SOURCE_TEXT_FIELDS}).
     * Используется при save товара и при AJAX {@see generateAi}.
     * 8 полей сырья
     * @return array<string, string>
     */
    private function sourceTextValidationRules(): array
    {
        $rules = [];
        foreach (Product::SOURCE_TEXT_FIELDS as $field) {
            $rules[$field] = 'nullable|string';
        }

        return $rules;
    }

    /**
     * Собирает из HTTP-запроса только поля сырья → массив для mass-assignment на `products`.
     * Отсутствующие ключи всё равно перечислены через SOURCE_TEXT_FIELDS.
     *
     * @return array<string, string|null>
     */
    private function sourceTextPayloadFromRequest(Request $request): array
    {
        $payload = []; // получаю массив с постом по всем полям и ложу их в массив
        foreach (Product::SOURCE_TEXT_FIELDS as $field) {
            $value = $request->input($field);
            $payload[$field] = is_string($value) ? $value : null;
        }

        return $payload;
    }

    /**
     * То же сырьё, что предыдущий метод, уже склеенное как для AI (разметка source id).
     * Нужен для логов / быстрой проверки «есть ли что-нибудь в форме», не сохраняя в БД.
     */
    private function combinedSourceTextFromRequest(Request $request): string
    {
        return Product::combineSourceSegments($this->sourceTextPayloadFromRequest($request));
    }
/*
Сначала код удаляет старые теги и пробелы (полностью «раздевает» текст до чистой сути).
А затем упаковывает этот чистый текст в новые, идеально ровные XML-теги с номером сегмента, чтобы ИИ прочитал данные без единой ошибки!
<source id="1">
Текст, который пользователь ввёл в поле source_text1
</source>

<source id="2">
Текст из поля source_text2
</source>

<source id="5">
А это текст из поля source_text5
</source>
*/
    /**
     * POST admin/products/generate-ai (AJAX с edit.blade: кнопка «Сгенерировать»).
     *
     * Порядок:
     *  1. Валидация + {@see Product::findOrFail(product_id)}.
     *  2. Сразу сохранить сырьё из формы в `products.source_text*` (чтобы воркер читал то же из БД).
     *  3. Цикл по каждому AI-полю × языку: есть ли уже длинный текст в `product_descriptions` → если да, задачу не ставим.
     *  4. Для пар «поле+язык» без контента собрать промпт из `prompt_category_descriptions`.
     *  5. В очередь цепочка: {@see ExtractProductGistJob} → {@see GenerateAiDescriptionsBatchJob} (этап 1, модели из *_model) → {@see DispatchAiFieldGenerationJobs} (этапы 2–3 на Flash).
     *
     * Светофоры на странице обновляет фронт через {@see checkAiStatus}.
     */
    public function generateAi(Request $request)
    {
        \Log::info('--- AI Generation Request Started ---', [
            'product_id'  => $request->input('product_id'),
            'ai_field'    => $request->input('ai_field'),
            'mode'        => $request->input('mode'),
            'has_result'  => !empty($request->input('result_text')),
            'has_source'  => $this->combinedSourceTextFromRequest($request) !== '',
        ]);

        // беру ключи с модели демкриптион
        $aiFields = array_keys($this->getAiPrefixedDescriptionFields());

        // Вместе с product_id и необязательным result_text принимаются source_text1..8 из JS.
        $data = $request->validate(array_merge([
            'product_id'  => ['required', 'integer', 'exists:products,id'],
            'result_text' => ['nullable', 'string'],
            'ai_field'    => ['nullable', 'string', Rule::in($aiFields)],
            'mode'        => ['sometimes', 'string', Rule::in(['batch', 'per_field'])],
            'ai_fields'   => ['sometimes', 'array'],
            'ai_fields.*' => ['string', Rule::in($aiFields)],
            'rebuild_extraction' => ['sometimes', 'boolean'],
            'extraction_model' => ['sometimes', 'string', Rule::in(['gemini-flash', 'openai-gpt-4o-mini'])],
            'description_models' => ['sometimes', 'array'],
            'description_models.*' => ['string', Rule::in(\App\Support\AiDescriptionModelChoice::keys())],
        ], $this->sourceTextValidationRules()));
// выниаю всю инфу
        $product = Product::findOrFail($data['product_id']);

        // Сырьё с экрана — заходт вбазу данных.
        $product->update($this->sourceTextPayloadFromRequest($request));
        $product->refresh();

        // здесь сырье оборачиванмя в теги <source id="'.$id.'">
        $baseText = trim($product->combinedSourceText());

        $mode = (string) ($data['mode'] ?? 'batch');
        $rebuildExtraction = (bool) ($data['rebuild_extraction'] ?? false);
        $extractionReady = $this->isExtractionReadyForProduct($product);
        $skipSourceCheck = $mode === 'per_field' && ! $rebuildExtraction && $extractionReady;

        if ($baseText === '' && ! $skipSourceCheck) {
            \Log::warning('AI Generation ABORTED: Empty source segments.', ['product_id' => $product->id]);
            return response()->json([
                'message' => 'Заполните хотя бы одно поле «Исходное сырьё» (1–8) или соберите выжимку.',
            ], 422);
        }

        if (mb_strlen($baseText) > ExtractProductGistJob::MAX_SOURCE_CHARS) {
            return response()->json([
                'message' => 'Сырьё слишком большое: '.mb_strlen($baseText).' символов. Максимум: '.ExtractProductGistJob::MAX_SOURCE_CHARS.' символов.',
            ], 422);
        }

        $sourceSha1 = hash('sha1', $baseText);
        $extractionModel = (string) ($data['extraction_model'] ?? 'gemini-flash');

        // Режим запуска: batch (все поля) или per_field (только выбранные галочками).
        $selectedFields = (array) ($data['ai_fields'] ?? []);

        // По умолчанию прогоняем все ключи из ProductDescription::AI_FIELDS
        // (см. getAiPrefixedDescriptionFields). В поштучном режиме — только отмеченные.
        if ($mode === 'per_field') {
            $targetFields = array_values(array_intersect($aiFields, $selectedFields));
        } else {
            $targetFields = $aiFields;
        }
     
        $extractionOnly = $mode === 'per_field' && $rebuildExtraction && $targetFields === [];

        if ($targetFields === [] && ! $extractionOnly) {
            return response()->json([
                'message' => 'В поштучном режиме отметьте «Собрать выжимку» или выберите хотя бы одно AI‑поле.',
            ], 422);
        }

        if ($extractionOnly) {
            $this->clearAiGenerationStatusCache($product->id, []);
            Cache::put($this->aiExtractionStartedCacheKey($product->id), time(), 86400);

            ExtractProductGistJob::dispatch($product, $baseText, $extractionModel);

            \Log::info('[generateAi] Только выжимка (поштучный режим)', [
                'product_id' => $product->id,
                'source_sha1' => $sourceSha1,
                'extraction_model' => $extractionModel,
            ]);

            return response()->json([
                'message' => 'Сбор выжимки запущен в фоне.',
                'extraction_only' => true,
            ]);
        }

        // Устаревающее поле ai_status для админских отображений — не главный источник светофора (см. Cache + checkAiStatus).
        $product->update(['ai_status' => json_encode(0)]);

        $languages = Language::all();

        \Log::info('Dispatching AiFieldGeneratorJob workers to Queue...', [
            'product_id' => $product->id,
            'manufacturer_id' => $product->manufacturer_id,
            'fields' => $targetFields,
            'char_count' => mb_strlen($baseText),
            'languages_count' => $languages->count(),
            'mode' => $mode,
        ]);

        // Сброс старых флагов ошибок/статуса (иначе повторный «Сгенерировать» сразу даёт api_error_cache).
        $this->clearAiGenerationStatusCache($product->id, $targetFields);

        $shouldRunExtraction = $mode === 'per_field' ? $rebuildExtraction : true;
        if ($shouldRunExtraction) {
            Cache::put($this->aiExtractionStartedCacheKey($product->id), time(), 86400);
        }

        // Список пар (language_id, target_field, prompts) только для строк, где реально будем звать AI после выжимки.
        $generationJobs = [];

        foreach ($targetFields as $targetField) {
            foreach ($languages as $language) {
                // 1. Проверка существования записи в целевой таблице
                $exists = DB::table('product_descriptions')
                    ->where('product_id', $product->id)
                    ->where('language_id', $language->id)
                    ->exists();

/*
SELECT EXISTS (
    SELECT 1
    FROM product_descriptions
    WHERE product_id = 123
      AND language_id = 1
);

получаю тру или фолсе SELECT EXISTS
если поле уже норм заполнено ! $exists → не трогай его continue;
*/


                if (! $exists) {
                    \Log::warning('[generateAi] Пропуск: нет записи в product_descriptions', [
                        'product_id' => $product->id,
                        'lang' => $language->id,
                        'field' => $targetField,
                    ]);
                    continue;
                }

                // возьми значение одного конкретного поля из базы
                $currentValue = DB::table('product_descriptions')
                    ->where('product_id', $product->id)
                    ->where('language_id', $language->id)
                    ->value($targetField);
                /*
SELECT ai_title
FROM product_descriptions
WHERE product_id = 125
  AND language_id = 1
LIMIT 1;
                */


/*
1. взяли значение поля
2. привели к строке
3. проверили длину

если текст нормальный →
    не генерируем
    идём дальше

если текст пустой →
    идём генерировать
*/
                $currentText = trim(is_string($currentValue) ? $currentValue : (string) ($currentValue ?? ''));
                if ($currentText !== '' && mb_strlen($currentText) > 50) {
                    \Log::info('Skip generation: already exists', [
                        'product_id' => $product->id,
                        'language_id' => $language->id,
                        'field' => $targetField,
                        'current_len' => mb_strlen($currentText),
                    ]);
                    continue;
                }

                // 3.  Выбирать промпт для конкретного товара + языка + поля + c какого сайта
                $mid = $product->manufacturer_id;
                $prompts = DB::selectOne('
                    SELECT d.*, c.id AS resolved_prompt_category_id
                    FROM `prompt_category_descriptions` AS d
                    INNER JOIN `prompt_categories` AS c ON d.prompt_category_id = c.id
                    WHERE c.ai_field = ? AND d.language_id = ? AND c.manufacturer_id = ?
                    ORDER BY c.sort_order ASC, c.id ASC
                    LIMIT 1
                ', [$targetField, $language->id, $mid]);

                if (! $prompts) {
                    \Log::error("КРИТИЧЕСКАЯ ОШИБКА: Промпт не найден для поля {$targetField} и языка {$language->id}.");
                    continue;
                }

                Cache::forget($this->aiGenerationErrorCacheKey($product->id, $targetField));
                // Метка времени «поле в работе» для {@see buildAiFieldStatusPayload} (жёлтый светофор).
                Cache::put($this->aiGenerationStartedCacheKey($product->id, $targetField), time(), 86400);

                // В батч-пайплайне эта запись уйдёт после выжимки; этап 1 читает description из prompts.
                $generationJobs[] = [
                    'language_id' => (int) $language->id,
                    'target_field' => (string) $targetField,
                    'prompts' => (array) $prompts,
                ];

                \Log::info('Dispatch generation', [
                    'product_id' => $product->id,
                    'language_id' => $language->id,
                    'field' => $targetField,
                    'prompt_id' => $prompts->id,
                ]);
            }
        }
// вызов конкретной ячейки и сто кней выбрано какой аи
        $descriptionModels = $this->resolveDescriptionModelsForGeneration($product, $request, $targetFields);

        // Запомнить модели этапа 1 в product_descriptions.*_model — при повторном открытии edit подтянутся в radio.
       // запись радиокнопок значений в базу данных
        $product->syncDescriptionModelSettings(array_merge(
            $product->descriptionModelSettings(),
            $descriptionModels
        ));

        // Динамическая цепочка: в batch‑режиме всегда делаем выжимку,
        // в поштучном режиме — только если явно включён чекбокс пересборки.
        $jobs = [];
        if ($shouldRunExtraction) {
            $jobs[] = new ExtractProductGistJob($product, $baseText, $extractionModel);
        }
        $jobs[] = new GenerateAiDescriptionsBatchJob($product, $generationJobs, $descriptionModels);
        $jobs[] = new DispatchAiFieldGenerationJobs($product, $generationJobs);

        Bus::chain($jobs)->dispatch();

        $languagesCount = Language::count();
        $modelGroups = array_count_values($descriptionModels);

        \Log::info('[generateAi] Цепочка: выжимка → пакетный description (модели × языки) → этапы 2–3 (Flash)', [
            'product_id' => $product->id,
            'source_sha1' => $sourceSha1,
            'generation_jobs_count' => count($generationJobs),
            'languages_count' => $languagesCount,
            'description_model_groups' => $modelGroups,
            'extraction_model' => $extractionModel,
            'run_extraction' => $shouldRunExtraction,
        ]);

        return response()->json([
            'message' => count($targetFields) > 1
                ? 'Генерация всех AI-полей успешно запущена в фоне.'
                : 'Генерация успешно запущена в фоне.',
        ]);
    }

    /**
     * AJAX: сохранить выбор модели этапа 1 в product_descriptions.*_model (сразу при клике на radio).
     */
    public function updateDescriptionModels(Request $request, string $id)
    {
        $product = Product::findOrFail($id);
        $fieldKeys = array_keys($this->getAiPrefixedDescriptionFields());

        $data = $request->validate([
            'field' => ['sometimes', 'string', Rule::in($fieldKeys)],
            'model' => ['sometimes', 'string', Rule::in(\App\Support\AiDescriptionModelChoice::keys())],
            'description_models' => ['sometimes', 'array'],
            'description_models.*' => ['string', Rule::in(\App\Support\AiDescriptionModelChoice::keys())],
        ]);

        $stored = $product->descriptionModelSettings();

        if (isset($data['field'], $data['model'])) {
            $stored[(string) $data['field']] = (string) $data['model'];
        }

        if (isset($data['description_models']) && is_array($data['description_models'])) {
            $stored = array_merge($stored, $data['description_models']);
        }

        $normalized = \App\Support\AiDescriptionModelChoice::normalize($stored, $fieldKeys);
        $product->syncDescriptionModelSettings($normalized);

        $labels = \App\Support\AiDescriptionModelChoice::labels();

        return response()->json([
            'ok' => true,
            'description_models' => $normalized,
            'labels' => array_map(
                fn (string $key) => $labels[$key] ?? $key,
                $normalized
            ),
        ]);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function resolveDescriptionModelsForProduct(Product $product, array $fields): array
    {
        return \App\Support\AiDescriptionModelChoice::normalize(
            $product->descriptionModelSettings(),
            $fields
        );
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string>
     */

     // подключение к файлу выбора аи

     // Это простой ассоциативный массив (карта), в котором для каждого текстового поля записана выбранная для него модель ИИ.
    private function resolveDescriptionModelsForGeneration(Product $product, Request $request, array $fields): array
    {
        $fromRequest = (array) $request->input('description_models', []);
        if ($fromRequest !== []) {
            return \App\Support\AiDescriptionModelChoice::normalize($fromRequest, $fields);
        }

        return $this->resolveDescriptionModelsForProduct($product, $fields);
    }

    /**
     * POST admin/products/extract-text (AJAX): из загруженного PDF / DOCX / TXT извлечь текст, прогнать через {@see normalizeExtractedTextToUtf8}, вернуть JSON с ключом `text`.
     *
     * Клиент подставляет строку в поля «исходное сырьё» на форме. Запуск AI и «светофор» — {@see generateAi}, {@see checkAiStatus}.
     */
    public function extractText(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:15360'],
        ]);

        $uploaded = $request->file('file');
        $ext = strtolower((string) $uploaded->getClientOriginalExtension());

        if (! in_array($ext, ['pdf', 'docx', 'txt'], true)) {
            return response()->json(['message' => 'Допустимые форматы: PDF, DOCX, TXT.'], 422);
        }

        $path = $uploaded->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return response()->json(['message' => 'Не удалось прочитать загруженный файл.'], 422);
        }

        try {
            $raw = match ($ext) {
                'pdf' => $this->extractTextFromPdfPath($path),
                'docx' => $this->extractTextFromDocxPath($path),
                'txt' => $this->extractTextFromTxtPath($path),
                default => '',
            };
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Ошибка разбора файла: '.$e->getMessage(),
            ], 422);
        }

        $text = $this->normalizeExtractedTextToUtf8((string) $raw);

        return response()->json(
            ['text' => $text],
            200,
            ['Content-Type' => 'application/json; charset=UTF-8'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private function extractTextFromPdfPath(string $path): string
    {
        $parser = new Parser;
        $pdf = $parser->parseFile($path);

        return $pdf->getText();
    }

    private function extractTextFromDocxPath(string $path): string
    {
        $phpWord = IOFactory::load($path);
        $buffer = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $buffer .= $this->extractTextFromPhpWordElement($element);
            }
        }

        return $buffer;
    }

    private function extractTextFromPhpWordElement(mixed $element): string
    {
        if ($element instanceof Text) {
            return $element->getText();
        }

        if ($element instanceof TextRun) {
            $parts = '';
            foreach ($element->getElements() as $child) {
                $parts .= $this->extractTextFromPhpWordElement($child);
            }

            return $parts;
        }

        if ($element instanceof Title) {
            $inner = $element->getText();
            if (is_string($inner)) {
                return $inner."\n";
            }

            return $this->extractTextFromPhpWordElement($inner)."\n";
        }

        if ($element instanceof PreserveText) {
            return $element->getText();
        }

        if ($element instanceof Table) {
            $block = '';
            foreach ($element->getRows() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cellText = '';
                    foreach ($cell->getElements() as $cellEl) {
                        $cellText .= $this->extractTextFromPhpWordElement($cellEl);
                    }
                    $cells[] = trim(preg_replace('/\s+/u', ' ', $cellText));
                }
                $block .= implode("\t", $cells)."\n";
            }

            return $block;
        }

        if ($element instanceof AbstractContainer) {
            $acc = '';
            foreach ($element->getElements() as $child) {
                $acc .= $this->extractTextFromPhpWordElement($child);
            }

            return $acc;
        }

        if (is_object($element) && method_exists($element, 'getText')) {
            $inner = $element->getText();
            if (is_string($inner)) {
                return $inner;
            }
            if (is_object($inner)) {
                return $this->extractTextFromPhpWordElement($inner);
            }
        }

        if (is_object($element) && method_exists($element, 'getElements')) {
            $acc = '';
            foreach ($element->getElements() as $child) {
                $acc .= $this->extractTextFromPhpWordElement($child);
            }

            return $acc;
        }

        return '';
    }

    private function extractTextFromTxtPath(string $path): string
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Пустой или недоступный TXT.');
        }

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        return $raw;
    }

    private function normalizeExtractedTextToUtf8(string $raw): string
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $detected = mb_detect_encoding($raw, ['UTF-8', 'Windows-1251', 'ISO-8859-1', 'CP1252'], true);
            if ($detected !== false && $detected !== 'UTF-8') {
                $converted = mb_convert_encoding($raw, 'UTF-8', $detected);
                if ($converted !== false) {
                    $raw = $converted;
                }
            } else {
                $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
            }
        }

        return $raw;
    }
    /**
     *  методы для пробразования в текст
     * POST admin/products/extract-text: извлечение текста из PDF / DOCX / TXT для поля «сырьё».
     */
    public function index(Request $request)
    {
        $pageTitle = 'Посты';
        $defaultLanguage = Language::getDefault();
        $productsQuery = Product::query()
            ->with([
                'descriptions' => fn ($q) => $q->where('language_id', $defaultLanguage?->id),
                'manufacturer',
                'author',
                'categories.descriptions' => fn ($q) => $q->where('language_id', $defaultLanguage?->id),
            ])
            ->orderByDesc('id');

        $selectedAuthor = $request->query('author');
        if ($selectedAuthor) {
            $productsQuery->where('author_id', (int) $selectedAuthor);
        }

        $selectedCategory = $request->query('category');
        if ($selectedCategory) {
            $productsQuery->whereHas('categories', function ($q) use ($selectedCategory) {
                $q->where('categories.id', (int) $selectedCategory);
            });
        }

        $selectedManufacturer = $request->query('manufacturer');
        if ($selectedManufacturer) {
            $productsQuery->where('manufacturer_id', (int) $selectedManufacturer);
        }

        $selectedMonth = $request->query('month');
        if ($selectedMonth && preg_match('/^\d{4}-\d{2}$/', $selectedMonth) === 1) {
            [$year, $month] = explode('-', $selectedMonth);
            $productsQuery
                ->whereYear('created_at', (int) $year)
                ->whereMonth('created_at', (int) $month);
        }

        $products = $productsQuery->paginate(20)->withQueryString();

        $authors = User::query()
            ->where(function ($q) {
                $q->whereNotNull('role_id')
                    ->orWhereNotNull('role');
            })
            ->whereIn('id', Product::query()->whereNotNull('author_id')->pluck('author_id')->unique())
            ->orderBy('name')
            ->get(['id', 'name']);

        $categories = Category::query()
            ->where('status', true)
            ->with(['descriptions' => fn ($q) => $q->where('language_id', $defaultLanguage?->id)])
            ->orderBy('sort_order')
            ->get();

        $manufacturers = Manufacturer::query()
            ->whereIn('id', Product::query()->whereNotNull('manufacturer_id')->pluck('manufacturer_id')->unique())
            ->orderBy('name')
            ->get(['id', 'name']);

        $months = Product::query()
            ->whereNotNull('created_at')
            ->orderByDesc('created_at')
            ->get(['created_at'])
            ->map(fn ($p) => optional($p->created_at)->format('Y-m'))
            ->filter()
            ->unique()
            ->values();

        return view('admin.products.index', compact(
            'products',
            'pageTitle',
            'defaultLanguage',
            'authors',
            'categories',
            'manufacturers',
            'months',
            'selectedAuthor',
            'selectedCategory',
            'selectedManufacturer',
            'selectedMonth'
        ));
    }

    public function create()
    {
        $pageTitle = 'Пост — создание';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $manufacturers = Manufacturer::orderBy('sort_order')->orderBy('name')->get();
        $categories = Category::with('descriptions')->orderBy('sort_order')->get();
        $aiFields = $this->getAiPrefixedDescriptionFields();
        $nextModel = (string) ((int) Product::max('id') + 1);

        return view('admin.products.create', compact('pageTitle', 'languages', 'defaultLanguage', 'manufacturers', 'categories', 'aiFields', 'nextModel'));
    }

    public function store(Request $request)
    {
        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык — после этого в формах появятся поля названий.');
        }

        $rules = array_merge([
            'model' => ['required', 'string', 'max:64', Rule::unique('products', 'model')],
            'manufacturer_id' => 'required|exists:manufacturers,id',
            'status' => 'nullable|boolean',
            'result' => 'nullable|string',
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'exists:categories,id',
        ], $this->sourceTextValidationRules());
        $aiFieldKeys = array_keys($this->getAiPrefixedDescriptionFields());
        $messages = [];
        foreach ($languages as $language) {
            $suffix = $language->code;
            $rules['name_'.$suffix] = 'required|string|max:255';
            $rules['slug_'.$suffix] = [
                'required', 'string', 'max:255',
                Rule::unique('product_descriptions', 'slug')->where('language_id', $language->id),
            ];
            $rules['description_'.$suffix] = 'nullable|string';
            foreach ($aiFieldKeys as $aiField) {
                $rules[$aiField.'_'.$suffix] = 'nullable|string';
            }

            $messages['name_'.$suffix.'.required'] = 'Не заполнено название для языка: '.$language->name.'.';
            $messages['slug_'.$suffix.'.required'] = 'Не заполнен slug для языка: '.$language->name.'.';
        }
        $messages['model.required'] = 'Поле Model обязательно для заполнения.';
        $messages['manufacturer_id.required'] = 'Поле Сайт обязательно для заполнения.';
        $messages['category_ids.required'] = 'Выберите категорию.';
        $messages['category_ids.min'] = 'Выберите хотя бы одну категорию.';

        $this->mergeLocalizedSlugsFromRequest($request, $languages);

        $request->validate($rules, $messages);

        DB::transaction(function () use ($request, $languages, $aiFieldKeys) {
            $authorId = Auth::id();

            $product = Product::create(array_merge([
                'model' => $request->model,
                'sku' => $request->input('sku'),
                // Поле image оставлено в БД, но скрыто из админ-формы.
                'image' => null,
                'manufacturer_id' => $request->input('manufacturer_id'),
                'author_id' => $authorId,
                'result' => $request->input('result'),
                'status' => $request->boolean('status'),
            ], $this->sourceTextPayloadFromRequest($request)));

            $product->categories()->sync($request->category_ids);

            foreach ($languages as $language) {
                $suffix = $language->code;
                $name = $request->input('name_'.$suffix, '');
                $slugInput = $request->input('slug_'.$suffix);
                if (! $language->is_default && $name === '' && ($slugInput === null || $slugInput === '')) {
                    continue;
                }
                $slug = (string) $slugInput;
                if (! $slug) {
                    continue;
                }
                $descriptionPayload = [
                    'product_id' => $product->id,
                    'language_id' => $language->id,
                    'name' => $name ?: $slug,
                    'slug' => $slug,
                    'description' => $request->input('description_'.$suffix),
                    'tag' => $request->input('tag_'.$suffix),
                    'meta_title' => $request->input('meta_title_'.$suffix),
                    'meta_description' => $request->input('meta_description_'.$suffix),
                    'meta_keyword' => $request->input('meta_keyword_'.$suffix),
                    'result' => $this->resolveProductDescriptionSourceRaw($request),
                ];
                foreach ($aiFieldKeys as $aiField) {
                    $rawAiValue = $request->input($aiField.'_'.$suffix);
                    $descriptionPayload[$aiField] = is_string($rawAiValue)
                        ? $rawAiValue
                        : (($rawAiValue === null) ? '' : (string) $rawAiValue);
                }
                ProductDescription::create($descriptionPayload);
            }

            $product->syncDescriptionModelSettings(
                \App\Support\AiDescriptionModelChoice::defaultsForFields($aiFieldKeys)
            );
        });

        return redirect()->route('admin.products.index')->with('success', 'Пост создан');
    }

    public function edit(string $id)
    {
        $pageTitle = 'Редактирование';
        // Загружаем товар со связями, чтобы не было N+1 запросов
        $product = Product::with(['descriptions', 'categories'])->findOrFail($id);
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $manufacturers = Manufacturer::orderBy('sort_order')->orderBy('name')->get();
        $categories = Category::with('descriptions')->orderBy('sort_order')->get();
        
        // Получаем список полей с префиксом ai_ (те самые ключи для семафора)
        $aiFields = $this->getAiPrefixedDescriptionFields();
        $descriptionModelChoices = \App\Support\AiDescriptionModelChoice::labels();
        $descriptionModelDefaults = $this->resolveDescriptionModelsForProduct($product, array_keys($aiFields));

        return view('admin.products.edit', compact(
            'product',
            'pageTitle',
            'languages',
            'defaultLanguage',
            'manufacturers',
            'categories',
            'aiFields',
            'descriptionModelChoices',
            'descriptionModelDefaults'
        ));
    }

    public function update(Request $request, string $id)
    {
        $product = Product::with('descriptions')->findOrFail($id);
        $languages = Language::forAdminForms();
        
        // Проверка на наличие языков, чтобы не посыпались ошибки в циклах ниже
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык — после этого в формах появятся поля названий.');
        }

        // Базовые правила валидации для основных полей товара
        $rules = array_merge([
            'model' => ['required', 'string', 'max:64', Rule::unique('products', 'model')->ignore($product->id)],
            'manufacturer_id' => 'required|exists:manufacturers,id',
            'status' => 'nullable|boolean',
            'result' => 'nullable|string',
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'exists:categories,id',
        ], $this->sourceTextValidationRules());

        // Динамическая валидация для каждого языка
        $aiFieldKeys = array_keys($this->getAiPrefixedDescriptionFields());
        $messages = [];
        foreach ($languages as $language) {
            $suffix = $language->code;
            
            $rules['name_'.$suffix] = 'required|string|max:255';
            
            $desc = $product->descriptions->firstWhere('language_id', $language->id);
            
            $rules['slug_'.$suffix] = [
                'required', 'string', 'max:255',
                Rule::unique('product_descriptions', 'slug')->where('language_id', $language->id)->ignore($desc?->id),
            ];
            
            // Правила для описаний и наших AI-полей
            $rules['description_'.$suffix] = 'nullable|string';
            foreach ($aiFieldKeys as $aiField) {
                $rules[$aiField.'_'.$suffix] = 'nullable|string';
            }

            $messages['name_'.$suffix.'.required'] = 'Не заполнено название для языка: '.$language->name.'.';
            $messages['slug_'.$suffix.'.required'] = 'Не заполнен slug для языка: '.$language->name.'.';
        }
        $messages['model.required'] = 'Поле Model обязательно для заполнения.';
        $messages['manufacturer_id.required'] = 'Поле Сайт обязательно для заполнения.';
        $messages['category_ids.required'] = 'Выберите категорию.';
        $messages['category_ids.min'] = 'Выберите хотя бы одну категорию.';

        // Обработка автоматических слагов перед валидацией
        $this->mergeLocalizedSlugsFromRequest($request, $languages);

        $request->validate($rules, $messages);

        // Все изменения в БД оборачиваем в транзакцию — либо всё сохранится, либо ничего
        DB::transaction(function () use ($request, $languages, $product, $aiFieldKeys) {
            $authorId = Auth::id();

            // Обновление основной таблицы товара
            $product->update(array_merge([
                'model' => $request->model,
                'sku' => $request->input('sku'),
                'manufacturer_id' => $request->input('manufacturer_id'),
                'author_id' => $authorId,
                'result' => $request->input('result'),
                'status' => $request->boolean('status'),
            ], $this->sourceTextPayloadFromRequest($request)));

            $modelsFromForm = (array) $request->input('description_models', []);
            if ($modelsFromForm !== []) {
                $product->syncDescriptionModelSettings(
                    \App\Support\AiDescriptionModelChoice::normalize($modelsFromForm, $aiFieldKeys)
                );
            }
            
            // Синхронизация категорий (многие-ко-многим)
            $product->categories()->sync($request->category_ids);

            // Сохранение мультиязычных описаний
            foreach ($languages as $language) {
                $suffix = $language->code;
                $name = $request->input('name_'.$suffix, '');
                $slugInput = $request->input('slug_'.$suffix);
                
                // Если язык не дефолтный и поля пустые — удаляем описание для этого языка
                if (! $language->is_default && $name === '' && ($slugInput === null || $slugInput === '')) {
                    ProductDescription::query()
                        ->where('product_id', $product->id)
                        ->where('language_id', $language->id)
                        ->delete();
                    continue;
                }
                
                $slug = (string) $slugInput;
                if (! $slug) {
                    continue;
                }
                
                // Основная магия записи AI-полей с нормализацией JSON
                $descriptionPayload = [
                    'name' => $name ?: $slug,
                    'slug' => $slug,
                    'description' => $request->input('description_'.$suffix),
                    'tag' => $request->input('tag_'.$suffix),
                    'meta_title' => $request->input('meta_title_'.$suffix),
                    'meta_description' => $request->input('meta_description_'.$suffix),
                    'meta_keyword' => $request->input('meta_keyword_'.$suffix),
                    'result' => $this->resolveProductDescriptionSourceRaw($request),
                ];
                foreach ($aiFieldKeys as $aiField) {
                    $rawAiValue = $request->input($aiField.'_'.$suffix);
                    $descriptionPayload[$aiField] = is_string($rawAiValue)
                        ? $rawAiValue
                        : (($rawAiValue === null) ? '' : (string) $rawAiValue);
                }
                ProductDescription::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'language_id' => $language->id,
                    ],
                    $descriptionPayload
                );
            }
        });

        return redirect()->route('admin.products.index')->with('success', 'Пост обновлён');
    }

    public function destroy(string $id)
    {
        Product::findOrFail($id)->delete();

        return redirect()->route('admin.products.index')->with('success', 'Пост удалён');
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:products,id'],
        ]);

        $products = Product::query()
            ->whereIn('id', $data['ids'])
            ->get();

        foreach ($products as $product) {
            $product->delete();
        }

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Выбранные посты удалены: '.$products->count());
    }

    /**
     * Колонка product_descriptions.result: приоритет у «Исходное сырьё» (source_text), если пусто — буфер «Результат» (поле result у товара).
     */
    private function resolveProductDescriptionSourceRaw(Request $request): ?string
    {
        $combined = trim($this->combinedSourceTextFromRequest($request));
        if ($combined !== '') {
            return $combined;
        }

        $buf = trim((string) $request->input('result', ''));
        if ($buf !== '') {
            return $request->input('result');
        }

        return null;
    }

    /**
     * Собирает список колонок таблицы product_descriptions, имена которых начинаются с ai_.
     *
     * Формат возврата: [ 'ai_some_field' => 'Подпись для админки', ... ].
     * Используется в create/edit (селект поля для генерации) и в generateAi (Rule::in по array_keys).
     *
     * Источник правды — схема БД: новая миграция с колонкой ai_* попадёт сюда без правок PHP,
     * кроме случая, когда нужна особая русская подпись — тогда добавляют ключ в $labels.
     */
    private function getAiPrefixedDescriptionFields(): array
    {
        return ProductDescription::aiFieldLabels();
    }

    public function checkAiStatus(Request $request, string $id)
    {
      //  Ты берешь список всех твоих AI-полей (тот самый «Золотой стандарт»). Это нужно, чтобы сервер не пытался проверить статус поля, которого не существует.
        $allowed = array_keys($this->getAiPrefixedDescriptionFields());
        $request->validate([
            'field' => ['sometimes', 'nullable', 'string', Rule::in($allowed)],
            'languages' => ['sometimes', 'nullable'],
        ]);
//Ты смотришь, пришел ли запрос на проверку одного конкретного поля или всех сразу.
        $field = (string) $request->query('field');
        $expectedCodes = $this->resolveExpectedLanguageCodes($request);


        //Если ты в JS передал конкретное поле, сервер мгновенно вызывает buildAiFieldStatusPayload. Тот заглядывает в кэш и базу, и ты сразу возвращаешь ответ. Это работает быстро.
        $product = Product::with('descriptions')->findOrFail($id);
        if ($field !== '') {
            $single = $this->buildAiFieldStatusPayload($product, $field, $expectedCodes);
            return response()->json($single);
        }

        $extraction = $this->buildAiExtractionStatusPayload($product);
        $fields = [];
        $hasError = $extraction['status'] === 'error';
        $allReady = $extraction['is_ready'] === true;

        foreach ($allowed as $allowedField) {
            $payload = $this->buildAiFieldStatusPayload($product, $allowedField, $expectedCodes);
            $fields[$allowedField] = $payload;
            $hasError = $hasError || ($payload['status'] === 'error');
            $allReady = $allReady && ($payload['is_ready'] === true);
        }

        $status = $allReady ? 'success' : ($hasError ? 'error' : 'processing');

        return response()->json([
            'is_ready' => $allReady,
            'status' => $status,
            'extraction' => $extraction,
            'fields' => $fields,
            'timeout_seconds' => (int) config('ai.generation.timeout_seconds', 3600),
        ]);
    }

    private function isExtractionReadyForProduct(Product $product): bool
    {
        $sourceText = trim($product->combinedSourceText());
        $resultText = trim((string) ($product->result ?? ''));
        $sourceSha1 = $sourceText !== '' ? hash('sha1', $sourceText) : '';
        $resultSourceSha1 = (string) ($product->result_source_sha1 ?? '');

        return $sourceSha1 !== ''
            && $resultText !== ''
            && hash_equals($resultSourceSha1, $sourceSha1);
    }

    private function buildAiExtractionStatusPayload(Product $product): array
    {
        $resultText = trim((string) ($product->result ?? ''));
        $isReady = $this->isExtractionReadyForProduct($product);

        $startedKey = $this->aiExtractionStartedCacheKey($product->id);
        $errorKey = $this->aiExtractionErrorCacheKey($product->id);
        $startedAt = Cache::get($startedKey);
        $timeoutSec = (int) config('ai.generation.timeout_seconds', 3600);
        $errorPayload = Cache::get($errorKey);
        $hasErrorFlag = Cache::has($errorKey);
        $timedOut = is_int($startedAt)
            && (time() - $startedAt) > $timeoutSec
            && ! $isReady;

        $errorReason = null;
        $errorMessage = null;
        if ($hasErrorFlag) {
            $errorReason = 'api_error_cache';
            $errorMessage = is_array($errorPayload) ? ($errorPayload['message'] ?? null) : null;
        } elseif ($timedOut) {
            $errorReason = 'timeout';
        }

        if ($isReady) {
            Cache::forget($startedKey);
            Cache::forget($errorKey);
            $status = 'success';
        } elseif ($hasErrorFlag || $timedOut) {
            $status = 'error';
        } elseif (is_int($startedAt)) {
            $status = 'processing';
        } else {
            $status = 'idle';
        }

        return [
            'is_ready' => $isReady,
            'status' => $status,
            'error_reason' => $errorReason,
            'error_message' => $errorMessage,
            'timeout_seconds' => $timeoutSec,
            'started_at' => $startedAt,
            'result_len' => mb_strlen($resultText),
        ];
    }

    private function buildAiFieldStatusPayload(Product $product, string $field, array $expectedCodes): array
    {
        $codeToRaw = $this->mapAiFieldValuesByLanguageCode($product, $field);
        $missingLanguages = [];

        foreach ($expectedCodes as $code) {
            $code = strtolower((string) $code);
            if (! isset($codeToRaw[$code]) || ! $this->productDescriptionAiFieldIsComplete($codeToRaw[$code])) {
                $missingLanguages[] = $code;
            }
        }

        $isReady = $expectedCodes !== [] && $missingLanguages === [];

        $startedKey = $this->aiGenerationStartedCacheKey($product->id, $field);
        $errorKey = $this->aiGenerationErrorCacheKey($product->id, $field);
        $startedAt = Cache::get($startedKey);
        $timeoutSec = (int) config('ai.generation.timeout_seconds', 7200);

        $errorPayload = Cache::get($errorKey);
        $errorPayload = is_array($errorPayload) ? $errorPayload : null;
        $hasApiErrorFlag = $errorPayload !== null;
        $timedOut = is_int($startedAt)
            && (time() - $startedAt) > $timeoutSec
            && ! $isReady;
        $hasFailedJob = is_int($startedAt)
            && $this->recentFailedAiFieldGeneratorJobMatchesProduct($product->id, $startedAt);

        $isError = $hasApiErrorFlag || $timedOut || $hasFailedJob;

        $errorReason = null;
        $errorMessage = null;
        if ($isError) {
            if ($errorPayload !== null) {
                $errorReason = AiGenerationErrorReason::fromPayload($errorPayload) ?? AiGenerationErrorReason::API_ERROR;
                $errorMessage = isset($errorPayload['message']) ? (string) $errorPayload['message'] : null;
            } elseif ($hasFailedJob) {
                $errorReason = AiGenerationErrorReason::FAILED_JOB;
            } elseif ($timedOut) {
                $errorReason = AiGenerationErrorReason::TIMEOUT;
            } elseif ($hasApiErrorFlag) {
                $errorReason = AiGenerationErrorReason::API_ERROR;
            }
        }

        if ($isReady) {
            Cache::forget($startedKey);
            Cache::forget($errorKey);
            $status = 'success';
        } elseif ($isError) {
            $isReady = false;
            $status = 'error';
        } elseif (is_int($startedAt)) {
            $status = 'processing';
        } else {
            $status = 'idle';
        }

        return [
            'is_ready' => $isReady,
            'status' => $status,
            'missing_languages' => $missingLanguages,
            'error_reason' => $errorReason,
            'error_reason_label' => $errorReason !== null ? AiGenerationErrorReason::label($errorReason) : null,
            'error_message' => $errorMessage,
            'timeout_seconds' => $timeoutSec,
            'started_at' => $startedAt,
        ];
    }

    /**
     * @return list<string> нижний регистр, уникально
     */
    private function resolveExpectedLanguageCodes(Request $request): array
    {
        $fromRequest = $request->input('languages');
        if (is_string($fromRequest) && trim($fromRequest) !== '') {
            $fromRequest = array_filter(array_map('trim', explode(',', $fromRequest)));
        }
        if (! is_array($fromRequest) || $fromRequest === []) {
            $fromRequest = Language::codesForAiChecks();
        }
        $codes = [];
        foreach ($fromRequest as $c) {
            $codes[] = strtolower((string) $c);
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return array<string, mixed> code => сырое значение колонки ai_* (для декодирования в проверке)
     */
    private function mapAiFieldValuesByLanguageCode(Product $product, string $field): array
    {
        $idToCode = Language::query()->pluck('code', 'id')->all();
        $map = [];
        foreach ($product->descriptions as $desc) {
            $code = $idToCode[$desc->language_id] ?? null;
            if ($code === null || $code === '') {
                continue;
            }
            $map[strtolower((string) $code)] = $desc->getAttribute($field);
        }

        return $map;
    }

    private function aiGenerationErrorCacheKey(int $productId, string $field): string
    {
        return 'product_ai_generation_error:'.$productId.':'.$field;
    }

    private function aiGenerationStartedCacheKey(int $productId, string $field): string
    {
        return 'product_ai_generation_started_at:'.$productId.':'.$field;
    }

    private function aiExtractionErrorCacheKey(int $productId): string
    {
        return 'product_ai_extraction_error:'.$productId;
    }

    private function aiExtractionStartedCacheKey(int $productId): string
    {
        return 'product_ai_extraction_started_at:'.$productId;
    }

    /**
     * Перед новым прогоном: убрать «залипшие» product_ai_generation_error / started_at по всем ai_* полям.
     *
     * @param  list<string>  $fields
     */
    private function clearAiGenerationStatusCache(int $productId, array $fields): void
    {
        Cache::forget($this->aiExtractionErrorCacheKey($productId));
        Cache::forget($this->aiExtractionStartedCacheKey($productId));
        Cache::forget(\App\Jobs\GenerateAiDescriptionsBatchJob::failedLanguagesCacheKey($productId));

        foreach ($fields as $field) {
            Cache::forget($this->aiGenerationErrorCacheKey($productId, $field));
            Cache::forget($this->aiGenerationStartedCacheKey($productId, $field));
        }
    }

    /**
     * Упавший AiFieldGeneratorJob в failed_jobs после старта текущей генерации (по времени failed_at).
     */
    private function recentFailedAiFieldGeneratorJobMatchesProduct(int $productId, int $startedUnix): bool
    {
        $since = Carbon::createFromTimestamp($startedUnix);

        $candidates = DB::table('failed_jobs')
            ->where('failed_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(80)
            ->get(['payload']);

        foreach ($candidates as $row) {
            $payload = $row->payload ?? '';
            if (! is_string($payload) || ! str_contains($payload, 'AiFieldGeneratorJob')) {
                continue;
            }
            $pid = (string) (int) $productId;
            if (preg_match('/(?<!\d)i:'.preg_quote($pid, '/').';(?!\d)/', $payload) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Полное заполнение ai_*: JSON title/text_1/text_2 или длинный legacy-текст.
     */
    private function productDescriptionAiFieldIsComplete(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === '[]' || $value === 'null') {
            return false;
        }

        $data = json_decode((string) $value, true);
        if (is_array($data)) {
            // Основной формат нашего пайплайна.
            $isPrimaryShapeComplete = ! empty($data['title']) || ! empty($data['text_1']) || ! empty($data['text_2']);
            if ($isPrimaryShapeComplete) {
                return true;
            }

            // Фолбэк для альтернативных JSON-структур (например sections/layout):
            // если в JSON есть хоть какой-то непустой текст/скаляр, считаем поле заполненным.
            return $this->jsonArrayHasNonEmptyScalar($data);
        }

        return mb_strlen(trim((string) $value)) > 10;
    }

    private function jsonArrayHasNonEmptyScalar(array $data): bool
    {
        $hasContent = false;

        array_walk_recursive($data, function (mixed $item) use (&$hasContent): void {
            if (is_string($item) && trim($item) !== '') {
                $hasContent = true;
                return;
            }
            if (is_int($item) || is_float($item) || is_bool($item)) {
                $hasContent = true;
            }
        });

        return $hasContent;
    }
}
