<?php

namespace App\Services;

use App\Support\AiDescriptionModelChoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Пакетная генерация этапа description: группировка полей по модели, один запрос на группу и язык.
 */
class AiDescriptionBatchService
{
    public function __construct(
        private GeminiService $gemini,
        private GeminiProService $geminiPro,
        private OpenAiService $openAi,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $fieldsPrompts  field => prompts row (description, …)
     * @param  array<string, string>  $fieldModels  field => model key
     */
    public function generateForLanguage(
        int $productId,
        int $languageId,
        array $fieldsPrompts,
        string $gist,
        array $fieldModels
    ): void {
        if ($fieldsPrompts === []) {
            return;
        }

        $fieldInstructions = [];
        foreach ($fieldsPrompts as $field => $prompts) {
            $description = trim((string) ($prompts['description'] ?? ''));
            if ($description === '') {
                throw new RuntimeException(
                    'Пустой description для поля '.$field.' (язык '.$languageId.').'
                );
            }
            $fieldInstructions[$field] = $description;
        }

        $groups = $this->groupFieldsByModel(array_keys($fieldInstructions), $fieldModels);
        $merged = [];

        foreach ($groups as $modelKey => $fieldsInGroup) {
            $subset = [];
            foreach ($fieldsInGroup as $field) {
                $subset[$field] = $fieldInstructions[$field];
            }

            Log::info('[AiDescriptionBatchService] Пакет description', [
                'product_id' => $productId,
                'language_id' => $languageId,
                'model_key' => $modelKey,
                'fields' => array_keys($subset),
                'gist_len' => mb_strlen($gist),
            ]);

            $instruction = $this->buildBatchInstruction($subset);
            $raw = $this->requestFromModel($modelKey, $gist, $instruction);
            $parsed = $this->parseFieldsJson($raw, array_keys($subset));
            $merged = array_merge($merged, $parsed);
        }

        $this->persistFieldTexts($productId, $languageId, $merged);
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, string>  $fieldModels
     * @return array<string, list<string>> modelKey => [fields]
     */
    private function groupFieldsByModel(array $fields, array $fieldModels): array
    {
        $groups = [];
        foreach ($fields as $field) {
            $modelKey = $fieldModels[$field] ?? AiDescriptionModelChoice::defaultForField($field);
            if (! in_array($modelKey, AiDescriptionModelChoice::keys(), true)) {
                $modelKey = AiDescriptionModelChoice::defaultForField($field);
            }
            $groups[$modelKey][] = $field;
        }

        return $groups;
    }

    private function requestFromModel(string $modelKey, string $gist, string $instruction): ?string
    {
        $timeout = (int) config(
            $modelKey === AiDescriptionModelChoice::GEMINI_PRO
                ? 'services.gemini_pro.chat_timeout'
                : 'services.gemini.chat_timeout',
            $modelKey === AiDescriptionModelChoice::GEMINI_PRO ? 1800 : 900
        );

        if ($modelKey === AiDescriptionModelChoice::GEMINI_FLASH) {
            return $this->gemini->chat($gist, $instruction, $timeout);
        }

        if ($modelKey === AiDescriptionModelChoice::GEMINI_PRO) {
            $maxOutputTokens = (int) config('services.gemini_pro.max_output_tokens', 65536);

            return $this->geminiPro->chat($gist, $instruction, $timeout, [
                'maxOutputTokens' => max(8192, $maxOutputTokens),
            ]);
        }

        if (AiDescriptionModelChoice::isOpenAi($modelKey)) {
            $openAiModel = AiDescriptionModelChoice::openAiModelId($modelKey);
            $maxOut = (int) config('services.openai.max_output_tokens', 16384);

            return $this->openAi->askOpenAiWithModel(
                $instruction,
                $gist,
                $openAiModel,
                'AiDescriptionBatch:'.$modelKey
            );
        }

        throw new RuntimeException('Неизвестная модель description: '.$modelKey);
    }
    /**
     * @param  array<string, string>  $fieldInstructions  field_name => description prompt
     */
    public function buildBatchInstruction(array $fieldInstructions): string
    {
        $parts = [
            'Задача: по одному исходному дайджесту (SOURCE TEXT ниже) сгенерировать тексты сразу для всех перечисленных полей.',
            'Верни ТОЛЬКО один валидный JSON-объект без markdown и без пояснений до/после.',
            'Ключи верхнего уровня — строго системные имена полей (латиница, как в списке).',
            'Значения — готовый текст для поля в том формате, который требует промпт этого поля.',
            'Если поле требует JSON — вставь вложенный JSON-объект/массив как значение ключа (не экранируй его внутри строки).',
            'Не пропускай ни одного ключа из списка.',
            '',
            '--- ПОЛЯ И ПРОМПТЫ ---',
        ];

        foreach ($fieldInstructions as $field => $instruction) {
            $parts[] = '';
            $parts[] = '### Поле: '.$field;
            $parts[] = trim($instruction);
        }

        $parts[] = '';
        $parts[] = '--- ФОРМАТ ОТВЕТА (пример структуры, не копируй содержимое) ---';
        $parts[] = '{';
        foreach (array_keys($fieldInstructions) as $field) {
            $parts[] = '  "'.$field.'": "…текст для этого поля…",';
        }
        $parts[] = '}';

        return implode("\n", $parts);
    }

    /**
     * @param  list<string>  $expectedKeys
     * @return array<string, string> field => text for DB column
     */
    public function parseFieldsJson(?string $raw, array $expectedKeys): array
    {
        if ($raw === null || trim($raw) === '') {
            throw new RuntimeException('Пустой ответ API для пакетной генерации description.');
        }

        $text = $this->stripResponseWrappers($raw);
        $decoded = $this->decodeJsonObject($text);

        if (! is_array($decoded)) {
            Log::error('[AiDescriptionBatchService] JSON decode failed', [
                'preview' => mb_substr($text, 0, 1200),
                'text_len' => mb_strlen($text),
            ]);
            throw new RuntimeException('Не удалось разобрать JSON ответа пакетной генерации description.');
        }

        $result = [];
        $missing = [];

        foreach ($expectedKeys as $field) {
            if (! array_key_exists($field, $decoded)) {
                $missing[] = $field;
                continue;
            }

            $value = $this->normalizeFieldValue($decoded[$field]);
            if ($value === '') {
                $missing[] = $field;
                continue;
            }

            $result[$field] = $value;
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'В JSON ответа нет или пусты поля: '.implode(', ', $missing)
            );
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $fieldTexts
     */
    public function persistFieldTexts(int $productId, int $languageId, array $fieldTexts): void
    {
        foreach ($fieldTexts as $field => $text) {
            $updated = DB::table('product_descriptions')
                ->where('product_id', $productId)
                ->where('language_id', $languageId)
                ->update([$field => $text]);

            if ($updated < 1) {
                throw new RuntimeException(
                    'Не удалось записать поле '.$field.' (product '.$productId.', language '.$languageId.').'
                );
            }
        }
    }

    private function normalizeFieldValue(mixed $value): string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $inner = json_decode($trimmed, true);
                if (is_array($inner)) {
                    return json_encode($inner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $trimmed;
                }
            }

            return $trimmed;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return '';
    }

    private function stripResponseWrappers(string $raw): string
    {
        $text = trim($raw);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/iu', $text, $matches)) {
            $text = trim($matches[1]);
        } elseif (str_starts_with(strtolower($text), '```json')) {
            $text = preg_replace('/^```json\s*/iu', '', $text) ?? $text;
            $text = preg_replace('/```\s*$/u', '', $text) ?? $text;
            $text = trim($text);
        }

        $text = preg_replace('/^\s*json\s*/iu', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $text): ?array
    {
        $decoded = json_decode($text, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/u', $text, $objectMatch)) {
            $decoded = json_decode($objectMatch[0], true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $this->tryRepairTruncatedJsonObject($text);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryRepairTruncatedJsonObject(string $text): ?array
    {
        $trimmed = rtrim($text);
        if ($trimmed === '' || ! str_starts_with($trimmed, '{')) {
            return null;
        }

        $candidates = [$trimmed];
        if ($this->jsonStringLikelyUnclosed($trimmed)) {
            $candidates[] = $trimmed.'"}';
            $candidates[] = $trimmed.'\"}';
        }

        $missingBraces = max(0, substr_count($trimmed, '{') - substr_count($trimmed, '}'));
        for ($i = 1; $i <= $missingBraces + 2; $i++) {
            $candidates[] = $trimmed.str_repeat('}', $i);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_array($decoded)) {
                Log::warning('[AiDescriptionBatchService] JSON восстановлен после обрезки ответа', [
                    'original_len' => mb_strlen($text),
                    'repaired_len' => mb_strlen($candidate),
                ]);

                return $decoded;
            }
        }

        return null;
    }

    private function jsonStringLikelyUnclosed(string $text): bool
    {
        $inString = false;
        $escaped = false;
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $char = $text[$i];
            if ($escaped) {
                $escaped = false;

                continue;
            }
            if ($char === '\\') {
                $escaped = true;

                continue;
            }
            if ($char === '"') {
                $inString = ! $inString;
            }
        }

        return $inString;
    }
}
