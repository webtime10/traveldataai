<?php

namespace App\Support;

use Throwable;

/**
 * Коды ошибок AI-генерации для светофора (checkAiStatus) и подсказок в админке.
 */
final class AiGenerationErrorReason
{
    public const FAILED_JOB = 'failed_job';

    public const TIMEOUT = 'timeout';

    public const API_ERROR = 'api_error_cache';

    /** Gemini / Google API вернул 503 (временная недоступность). */
    public const API_UNAVAILABLE_503 = 'api_unavailable_503';

    public const BATCH_JSON = 'batch_json_error';

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromPayload(?array $payload): ?string
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        $reason = (string) ($payload['reason'] ?? '');
        if ($reason !== '' && self::isKnown($reason)) {
            return $reason;
        }

        $httpStatus = (int) ($payload['http_status'] ?? 0);
        if ($httpStatus === 503) {
            return self::API_UNAVAILABLE_503;
        }

        return self::detectFromMessage((string) ($payload['message'] ?? ''));
    }

    public static function detectFromThrowable(?Throwable $exception): string
    {
        $message = $exception?->getMessage() ?? '';
        $fromMessage = self::detectFromMessage($message);

        return $fromMessage ?? self::API_ERROR;
    }

    public static function detectFromMessage(string $message): ?string
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        if (preg_match('/\b503\b/', $message) === 1
            || stripos($message, 'UNAVAILABLE') !== false
            || stripos($message, 'service unavailable') !== false) {
            return self::API_UNAVAILABLE_503;
        }

        if (stripos($message, 'разобрать JSON') !== false
            || stripos($message, 'JSON decode') !== false) {
            return self::BATCH_JSON;
        }

        return null;
    }

    public static function label(string $reason): string
    {
        return match ($reason) {
            self::API_UNAVAILABLE_503 => 'API Google временно недоступен (503)',
            self::BATCH_JSON => 'не удалось разобрать JSON ответа',
            self::FAILED_JOB => 'задача в очереди завершилась с ошибкой',
            self::TIMEOUT => 'превышено время ожидания',
            self::API_ERROR => 'ошибка API',
            default => $reason,
        };
    }

    public static function isKnown(string $reason): bool
    {
        return in_array($reason, [
            self::FAILED_JOB,
            self::TIMEOUT,
            self::API_ERROR,
            self::API_UNAVAILABLE_503,
            self::BATCH_JSON,
        ], true);
    }
}
