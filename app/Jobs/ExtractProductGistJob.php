<?php

namespace App\Jobs;

use App\Models\ExtractionPrompt;
use App\Models\Product;
use App\Services\GeminiService;
use App\Services\OpenAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ExtractProductGistJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHUNK_CHAR_LIMIT = 60000;

    public const WARN_SOURCE_CHARS = 240000;

    public const MAX_SOURCE_CHARS = 720000;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        public Product $product,
        public string $sourceText = '',
        public string $extractionModel = 'gemini-flash'
    ) {}

    public function handle(): void
    {
        $product = $this->product->fresh();
        if (! $product) {
            throw new RuntimeException('Товар для выжимки не найден.');
        }

        $sourceMaterial = trim($this->sourceText !== '' ? $this->sourceText : $product->combinedSourceText());
        if ($sourceMaterial === '') {
            throw new RuntimeException('Пустое исходное сырьё — выжимка невозможна.');
        }

        if (mb_strlen($sourceMaterial) > self::MAX_SOURCE_CHARS) {
            throw new RuntimeException(
                'Сырьё слишком большое: '.mb_strlen($sourceMaterial).' символов. Максимум: '.self::MAX_SOURCE_CHARS.' символов.'
            );
        }

        $sourceSha1 = hash('sha1', $sourceMaterial);
        $currentResult = trim((string) ($product->result ?? ''));
        $currentSha1 = (string) ($product->result_source_sha1 ?? '');

        Cache::put($this->startedCacheKey($product->id), time(), 86400);
        Cache::forget($this->errorCacheKey($product->id));

        if ($currentResult !== '' && hash_equals($currentSha1, $sourceSha1)) {
            Cache::forget($this->startedCacheKey($product->id));
            Log::info('[ExtractProductGistJob] Выжимка уже актуальна, Gemini не вызываем', [
                'product_id' => $product->id,
                'source_len' => mb_strlen($sourceMaterial),
                'result_len' => mb_strlen($currentResult),
                'source_sha1' => $sourceSha1,
            ]);

            return;
        }

        $prompt = ExtractionPrompt::active();
        if (! $prompt || trim((string) $prompt->prompt_text) === '') {
            throw new RuntimeException('Активный промпт для выжимки не найден в extraction_prompts.');
        }

        Log::info('[ExtractProductGistJob] Старт выжимки сырья', [
            'product_id' => $product->id,
            'source_len' => mb_strlen($sourceMaterial),
            'source_sha1' => $sourceSha1,
            'prompt_id' => $prompt->id,
            'prompt_key' => $prompt->key,
            'extraction_model' => $this->extractionModel,
        ]);

        $gist = $this->buildGist((string) $prompt->prompt_text, $sourceMaterial, $product->id);

        if ($gist === '') {
            throw new RuntimeException(
                'Модель выжимки не вернула текст (таймаут API, сеть или пустой ответ). См. laravel.log.'
            );
        }

        $product->forceFill([
            'result' => $gist,
            'result_source_sha1' => $sourceSha1,
        ])->save();

        Cache::forget($this->startedCacheKey($product->id));
        Cache::forget($this->errorCacheKey($product->id));

        Log::info('[ExtractProductGistJob] Выжимка сработала и записана в products.result', [
            'product_id' => $product->id,
            'source_len' => mb_strlen($sourceMaterial),
            'gist_len' => mb_strlen($gist),
            'source_sha1' => $sourceSha1,
        ]);
    }

    private function buildGist(string $promptText, string $sourceMaterial, int $productId): string
    {
        $chunks = $this->splitTextIntoChunks($sourceMaterial);

        if (count($chunks) === 1) {
            $result = $this->askExtractionModel($sourceMaterial, $promptText.$this->singlePassInstruction());

            return trim((string) ($result ?? ''));
        }

        Log::info('[ExtractProductGistJob] Сырьё разбито на части', [
            'product_id' => $productId,
            'chunks_count' => count($chunks),
            'chunk_limit' => self::CHUNK_CHAR_LIMIT,
            'source_len' => mb_strlen($sourceMaterial),
        ]);

        $partials = [];
        $total = count($chunks);
        foreach ($chunks as $index => $chunk) {
            $part = $index + 1;
            Log::info('[ExtractProductGistJob] Выжимка части', [
                'product_id' => $productId,
                'part' => $part,
                'total' => $total,
                'chunk_len' => mb_strlen($chunk),
            ]);

            $partial = $this->askExtractionModel($chunk, $promptText.$this->chunkInstruction($part, $total));
            $partialText = trim((string) ($partial ?? ''));
            if ($partialText === '') {
                throw new RuntimeException(
                    'Модель выжимки не вернула результат для части '.$part.' из '.$total.' (таймаут или пустой ответ). См. laravel.log.'
                );
            }

            $partials[] = "## Часть {$part} из {$total}\n".$partialText;
        }

        Log::info('[ExtractProductGistJob] Финальная сборка выжимки', [
            'product_id' => $productId,
            'partials_len' => mb_strlen(implode("\n\n---\n\n", $partials)),
            'chunks_count' => $total,
        ]);

        return $this->mergePartialsIntoGist($promptText, $partials, $total, $productId);
    }

    /**
     * @param  list<string>  $partials
     */
    private function mergePartialsIntoGist(
        string $promptText,
        array $partials,
        int $originalChunkTotal,
        int $productId
    ): string {
        $partialsText = implode("\n\n---\n\n", $partials);
        $len = mb_strlen($partialsText);

        if ($len <= 90000) {
            $final = $this->askExtractionModel($partialsText, $promptText.$this->mergeInstruction($originalChunkTotal));

            return trim((string) ($final ?? ''));
        }

        if (count($partials) <= 1) {
            Log::warning('[ExtractProductGistJob] Одна промежуточная выжимка слишком большая для финального запроса', [
                'product_id' => $productId,
                'partials_len' => $len,
            ]);
            $final = $this->askExtractionModel($partialsText, $promptText.$this->mergeInstruction($originalChunkTotal));

            return trim((string) ($final ?? ''));
        }

        $mid = (int) ceil(count($partials) / 2);
        Log::info('[ExtractProductGistJob] Двухэтапная финальная сборка (объём > 90k)', [
            'product_id' => $productId,
            'partials_len' => $len,
            'first_group' => $mid,
            'second_group' => count($partials) - $mid,
        ]);

        $mergedFirst = $this->mergePartialsIntoGist(
            $promptText,
            array_slice($partials, 0, $mid),
            $originalChunkTotal,
            $productId
        );
        $mergedSecond = $this->mergePartialsIntoGist(
            $promptText,
            array_slice($partials, $mid),
            $originalChunkTotal,
            $productId
        );

        if ($mergedFirst === '' || $mergedSecond === '') {
            return '';
        }

        $combined = "## Блок A\n".$mergedFirst."\n\n---\n\n## Блок B\n".$mergedSecond;
        $final = $this->askExtractionModel($combined, $promptText.$this->mergeInstruction(2));

        return trim((string) ($final ?? ''));
    }

    private function askExtractionModel(string $material, string $instruction): ?string
    {
        if ($this->extractionModel === 'openai-gpt-4o-mini') {
            /** @var OpenAiService $openAi */
            $openAi = app(OpenAiService::class);
            return $openAi->askOpenAiWithModel(
                trim($instruction),
                trim($material),
                'gpt-4o-mini',
                'openai.extraction.manual-model'
            );
        }

        /** @var GeminiService $gemini */
        $gemini = app(GeminiService::class);
        return $gemini->chatForExtraction($material, $instruction);
    }

    /**
     * @return list<string>
     */
    private function splitTextIntoChunks(string $text): array
    {
        $normalized = preg_replace("/\r\n?/", "\n", $text) ?? $text;
        $normalized = preg_replace("/[ \t]+/", ' ', $normalized) ?? $normalized;
        $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;
        $paragraphs = preg_split("/\n{2,}/", trim($normalized)) ?: [];

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) > self::CHUNK_CHAR_LIMIT) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach ($this->splitLongText($paragraph) as $part) {
                    $chunks[] = $part;
                }
                continue;
            }

            $candidate = $current === '' ? $paragraph : $current."\n\n".$paragraph;
            if (mb_strlen($candidate) > self::CHUNK_CHAR_LIMIT) {
                if ($current !== '') {
                    $chunks[] = $current;
                }
                $current = $paragraph;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [trim($normalized)] : $chunks;
    }

    /**
     * @return list<string>
     */
    private function splitLongText(string $text): array
    {
        $parts = [];
        $remaining = trim($text);

        while (mb_strlen($remaining) > self::CHUNK_CHAR_LIMIT) {
            $slice = mb_substr($remaining, 0, self::CHUNK_CHAR_LIMIT);
            $breakAt = max(
                (int) mb_strrpos($slice, '. '),
                (int) mb_strrpos($slice, '! '),
                (int) mb_strrpos($slice, '? '),
                (int) mb_strrpos($slice, "\n")
            );

            if ($breakAt < (int) (self::CHUNK_CHAR_LIMIT * 0.6)) {
                $breakAt = self::CHUNK_CHAR_LIMIT;
            }

            $parts[] = trim(mb_substr($remaining, 0, $breakAt));
            $remaining = trim(mb_substr($remaining, $breakAt));
        }

        if ($remaining !== '') {
            $parts[] = $remaining;
        }

        return $parts;
    }

    private function singlePassInstruction(): string
    {
        return "\n\nДополнительное ограничение: сделай финальную выжимку компактной, цель 30000-60000 символов, максимум 80000 символов. Убери повторы, но не теряй факты.";
    }

    private function chunkInstruction(int $part, int $total): string
    {
        return "\n\nЭто часть {$part} из {$total} большого документа. Сделай промежуточную выжимку только по этой части. Не делай общий вывод по всему документу. Цель: до 8000-10000 символов для этой части.";
    }

    private function mergeInstruction(int $total): string
    {
        return "\n\nПеред тобой {$total} промежуточных выжимок из одного документа. Собери одну финальную выжимку для дальнейшей генерации статей. Убери дубли между частями, сохрани все важные факты, имена, цифры, цены, маршруты и расписания. Цель финального текста: 30000-60000 символов, максимум 80000 символов.";
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget($this->startedCacheKey($this->product->id));
        Cache::put(
            $this->errorCacheKey($this->product->id),
            [
                'at' => time(),
                'message' => $exception?->getMessage(),
                'exception_class' => $exception ? $exception::class : null,
            ],
            now()->addDay()
        );

        Log::error('[ExtractProductGistJob] Выжимка остановлена (failed)', [
            'product_id' => $this->product->id,
            'message' => $exception?->getMessage(),
            'exception_class' => $exception ? $exception::class : null,
        ]);
    }

    private function startedCacheKey(int $productId): string
    {
        return 'product_ai_extraction_started_at:'.$productId;
    }

    private function errorCacheKey(int $productId): string
    {
        return 'product_ai_extraction_error:'.$productId;
    }
}
