<?php

namespace App\Support;

/**
 * Ключи моделей для этапа 1 (description) в админке и batch-генерации.
 */
final class AiDescriptionModelChoice
{
    public const GEMINI_FLASH = 'gemini-flash';

    public const GEMINI_PRO = 'gemini-pro';

    public const OPENAI_GPT_4O_MINI = 'openai-gpt-4o-mini';

    public const OPENAI_GPT_4O = 'openai-gpt-4o';

    public const OPENAI_GPT_54 = 'openai-gpt-5.4';

    /** @return list<string> */
    public static function keys(): array
    {
        return [
            self::GEMINI_FLASH,
            self::GEMINI_PRO,
            self::OPENAI_GPT_4O_MINI,
            self::OPENAI_GPT_4O,
            self::OPENAI_GPT_54,
        ];
    }

    /** Колонка в product_descriptions для выбранной модели этапа 1 (напр. ai_faq → ai_faq_model). */
    public static function modelColumnForField(string $aiField): string
    {
        return $aiField.'_model';
    }

    /** @return list<string> */
    public static function modelColumns(): array
    {
        return array_map(
            fn (string $field) => self::modelColumnForField($field),
            \App\Models\ProductDescription::aiFieldKeys()
        );
    }

    /** @return array<string, string> key => подпись в UI */
    public static function labels(): array
    {
        return [
            self::GEMINI_FLASH => 'Gemini Flash',
            self::GEMINI_PRO => 'Gemini Pro',
            self::OPENAI_GPT_4O_MINI => 'GPT-4o Mini',
            self::OPENAI_GPT_4O => 'GPT-4o',
            self::OPENAI_GPT_54 => 'GPT-5.4',
        ];
    }

    /** Дефолт этапа 1 для любого ai_* поля, если в БД ещё не сохранён выбор. */
    public static function defaultForField(string $field): string
    {
        return self::GEMINI_FLASH;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    public static function defaultsForFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = self::defaultForField($field);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    public static function normalize(array $input, array $fields): array
    {
        $allowed = array_flip(self::keys());
        $defaults = self::defaultsForFields($fields);
        $out = [];

        foreach ($fields as $field) {
            $raw = $input[$field] ?? null;
            $key = is_string($raw) ? trim($raw) : '';
            $out[$field] = isset($allowed[$key]) ? $key : $defaults[$field];
        }

        return $out;
    }

    public static function isOpenAi(string $modelKey): bool
    {
        return str_starts_with($modelKey, 'openai-');
    }

    public static function isGeminiPro(string $modelKey): bool
    {
        return $modelKey === self::GEMINI_PRO;
    }

    public static function openAiModelId(string $modelKey): string
    {
        return match ($modelKey) {
            self::OPENAI_GPT_4O_MINI => 'gpt-4o-mini',
            self::OPENAI_GPT_4O => 'gpt-4o',
            self::OPENAI_GPT_54 => trim((string) config('services.openai.model', 'gpt-5.4')) ?: 'gpt-5.4',
            default => throw new \InvalidArgumentException('Не OpenAI ключ модели: '.$modelKey),
        };
    }

}
