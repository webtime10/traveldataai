<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Один запрос к Gemini API: инструкция этапа + материал (как в OpenAiService::askOpenAi).
 */
class GeminiService
{
    /** Последний HTTP-статус при неуспешном ответе chat() (для меток светофора). */
    protected ?int $lastHttpStatus = null;

    public function __construct(
        protected string $configKeyPath = 'services.gemini.key',
        protected string $configModelPath = 'services.gemini.model',
        protected string $logTag = 'GeminiService',
        protected string $missingKeyEnvHint = 'GEMINI_API_KEY',
        protected string $missingModelEnvHint = 'GEMINI_MODEL',
    ) {}

    /**
     * @param  array<string, mixed>|null  $generationConfig  например ['maxOutputTokens' => 65536]
     */
    public function lastHttpStatus(): ?int
    {
        return $this->lastHttpStatus;
    }

    public function chat(string $material, string $instruction, int $timeoutSeconds = 180, ?array $generationConfig = null): ?string
    {
        $this->lastHttpStatus = null;
        $instruction = trim($instruction);
        $material = trim($material);

        if ($instruction === '' || $material === '') {
            Log::warning('['.$this->logTag.'] chat: пустая инструкция или материал', [
                'instruction_len' => mb_strlen($instruction),
                'material_len' => mb_strlen($material),
            ]);

            return null;
        }

        $preview = 900;
        Log::info('['.$this->logTag.'] pipeline material (before API)', [
            'call' => 'chat',
            'instruction_len' => mb_strlen($instruction),
            'material_len' => mb_strlen($material),
            'material_sha1' => hash('sha1', $material),
            'instruction_preview' => mb_substr($instruction, 0, $preview),
            'material_preview' => mb_substr($material, 0, $preview),
        ]);

        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            Log::error('['.$this->logTag.'] chat: не задан '.$this->missingKeyEnvHint);

            return null;
        }

        $model = trim((string) config($this->configModelPath, ''));
        if ($model === '') {
            Log::error('['.$this->logTag.'] chat: пустой '.$this->missingModelEnvHint);

            return null;
        }

        $userContent = $instruction."\n\n--- SOURCE TEXT ---\n".$material;
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            .rawurlencode($model)
            .':generateContent?key='.$apiKey;

        Log::info('['.$this->logTag.'] chat: HTTP request', [
            'model' => $model,
            'instruction_len' => mb_strlen($instruction),
            'material_len' => mb_strlen($material),
            'timeout_seconds' => max(30, $timeoutSeconds),
        ]);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $userContent]],
                ],
            ],
        ];
        if ($generationConfig !== null && $generationConfig !== []) {
            $payload['generationConfig'] = $generationConfig;
        }

        try {
            $response = Http::timeout(max(30, $timeoutSeconds))
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);
        } catch (Throwable $e) {
            Log::error('['.$this->logTag.'] chat: сеть/HTTP исключение', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->lastHttpStatus = $response->status();
            Log::error('['.$this->logTag.'] chat: неуспешный ответ API', [
                'status' => $this->lastHttpStatus,
                'body' => $this->truncateForLog($response->body()),
            ]);

            return null;
        }

        $finishReason = $response->json('candidates.0.finishReason');
        if (is_string($finishReason) && $finishReason !== '' && $finishReason !== 'STOP') {
            Log::warning('['.$this->logTag.'] chat: ответ обрезан или остановлен не полностью', [
                'finish_reason' => $finishReason,
                'model' => $model,
            ]);
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($text)) {
            Log::error('['.$this->logTag.'] chat: в JSON нет текста ответа', [
                'json_keys' => array_keys($response->json() ?? []),
                'finish_reason' => $finishReason,
            ]);

            return null;
        }

        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    /**
     * Выжимка сырья (ExtractProductGistJob) — тот же API, отдельное имя для логов.
     */
    public function chatForExtraction(string $material, string $instruction): ?string
    {
        $timeout = (int) config('services.gemini.extraction_timeout', 1800);

        return $this->chat($material, $instruction, $timeout);
    }

    /** Таймаут HTTP для этапов enliven/edit и batch (см. GEMINI_CHAT_TIMEOUT). */
    public function defaultChatTimeout(): int
    {
        return max(60, (int) config('services.gemini.chat_timeout', 900));
    }

    private function resolveApiKey(): string
    {
        $raw = (string) config($this->configKeyPath, '');
        $raw = trim($raw, " \t\n\r\0\x0B\"'");
        if ($raw === '') {
            return '';
        }

        $keys = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($keys === []) {
            return '';
        }

        return (string) $keys[array_rand($keys)];
    }

    private function truncateForLog(string $body, int $max = 4000): string
    {
        if (mb_strlen($body) <= $max) {
            return $body;
        }

        return mb_substr($body, 0, $max).'…';
    }
}
