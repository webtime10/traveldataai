<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductDescription;
use App\Services\AiDescriptionBatchService;
use App\Support\AiDescriptionModelChoice;
use App\Support\AiGenerationErrorReason;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Этап 1 (description): пакеты по группам моделей × язык.
 */
class GenerateAiDescriptionsBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static function failedLanguagesCacheKey(int $productId): string
    {
        return 'product_ai_batch_failed_languages:'.$productId;
    }

    //енерация через ИИ для кучи полей и языков занимает много времени. Мы даем этой задаче право выполняться на сервере до 2 часов, чтобы сервер не прибил её на полуслове.
    public int $timeout = 7200;
// если что-то сломалось (например, у OpenAI закончились деньги на балансе), мы делаем всего 1 попытку. Повторять задачу автоматически нельзя, иначе система по кругу начнет тратить деньги на повторные запросы.
    public int $tries = 1;

    /**
     * @param  list<array{language_id:int,target_field:string,prompts:array<string,mixed>}>  $generationJobs
     * @param  array<string, string>  $descriptionModels  field => model key (только этап 1)
     */
    public function __construct(
        public Product $product,
        public array $generationJobs,
        public array $descriptionModels = [],
    ) {}

    public function handle(AiDescriptionBatchService $batchService): void
    {
        $product = $this->product->fresh();
        if (! $product) {
            throw new RuntimeException('Товар для пакетной генерации description не найден.');
        }

        $gist = trim((string) ($product->result ?? ''));
        if ($gist === '') {
            throw new RuntimeException('Пустая выжимка products.result — пакетная генерация description невозможна.');
        }

        $allowedFields = ProductDescription::aiFieldKeys();
        $byLanguage = [];
//Код берет плоский список задач и пересобирает его в удобные "коробки" по языкам.
        foreach ($this->generationJobs as $job) {
            $languageId = (int) ($job['language_id'] ?? 0);
            $field = (string) ($job['target_field'] ?? '');

            if ($languageId < 1 || $field === '' || ! in_array($field, $allowedFields, true)) {
                continue;
            }

            if (! isset($byLanguage[$languageId])) {
                $byLanguage[$languageId] = [];
            }

            $byLanguage[$languageId][$field] = (array) ($job['prompts'] ?? []);
        }

        if ($byLanguage === []) {
            Log::warning('[GenerateAiDescriptionsBatchJob] Нет задач для пакетной генерации', [
                'product_id' => $product->id,
            ]);

            return;
        }

        $allFields = [];
        foreach ($byLanguage as $fieldsPrompts) {
            foreach (array_keys($fieldsPrompts) as $field) {
                $allFields[$field] = true;
            }
        }
        $stored = $product->descriptionModelSettings();
        $fieldModels = AiDescriptionModelChoice::normalize(
            $stored !== [] ? $stored : $this->descriptionModels,
            array_keys($allFields)
        );

        Log::info('[GenerateAiDescriptionsBatchJob] Старт пакетной генерации description', [
            'product_id' => $product->id,
            'languages_count' => count($byLanguage),
            'gist_len' => mb_strlen($gist),
            'model_groups' => array_count_values($fieldModels),
        ]);

        $failedLanguages = [];

        foreach ($byLanguage as $languageId => $fieldsPrompts) {
            try {
                $batchService->generateForLanguage(
                    $product->id,
                    (int) $languageId,
                    $fieldsPrompts,
                    $gist,
                    $fieldModels
                );

                Log::info('[GenerateAiDescriptionsBatchJob] Поля записаны после пакетного description', [
                    'product_id' => $product->id,
                    'language_id' => (int) $languageId,
                    'fields' => array_keys($fieldsPrompts),
                ]);
            } catch (Throwable $e) {
                $failedLanguages[] = (int) $languageId;
                Log::error('[GenerateAiDescriptionsBatchJob] Ошибка пакета для языка', [
                    'product_id' => $product->id,
                    'language_id' => (int) $languageId,
                    'message' => $e->getMessage(),
                ]);
                $this->markLanguageBatchError($product->id, array_keys($fieldsPrompts), $e);
            }
        }

        if ($failedLanguages !== [] && count($failedLanguages) === count($byLanguage)) {
            throw new RuntimeException(
                'Пакетная генерация description не удалась ни для одного языка.'
            );
        }

        // Фактически использованные модели — в БД (галки на edit после перезагрузки).
        $product->syncDescriptionModelSettings(array_merge(
            $product->descriptionModelSettings(),
            $fieldModels
        ));

        $failedKey = self::failedLanguagesCacheKey($product->id);
        if ($failedLanguages !== []) {
            Cache::put($failedKey, $failedLanguages, now()->addDay());
        } else {
            Cache::forget($failedKey);
        }

        Log::info('[GenerateAiDescriptionsBatchJob] Пакетная генерация description завершена', [
            'product_id' => $product->id,
            'languages_count' => count($byLanguage),
            'failed_languages' => $failedLanguages,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('[GenerateAiDescriptionsBatchJob] Пакетная генерация description остановлена (failed)', [
            'product_id' => $this->product->id,
            'message' => $exception?->getMessage(),
        ]);

        $fields = [];
        foreach ($this->generationJobs as $job) {
            $field = (string) ($job['target_field'] ?? '');
            if ($field !== '') {
                $fields[$field] = true;
            }
        }

        $this->markLanguageBatchError($this->product->id, array_keys($fields), $exception);
    }

    /**
     * @param  list<string>  $fields
     */
    private function markLanguageBatchError(int $productId, array $fields, ?Throwable $exception): void
    {
        foreach (array_unique($fields) as $field) {
            if ($field === '') {
                continue;
            }

            Cache::forget('product_ai_generation_started_at:'.$productId.':'.$field);
            Cache::put(
                'product_ai_generation_error:'.$productId.':'.$field,
                [
                    'at' => time(),
                    'reason' => AiGenerationErrorReason::detectFromThrowable($exception),
                    'message' => $exception?->getMessage(),
                    'exception_class' => $exception ? $exception::class : null,
                ],
                now()->addDay()
            );
        }
    }
}
