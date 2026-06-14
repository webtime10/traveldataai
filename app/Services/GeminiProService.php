<?php

namespace App\Services;

/**
 * Платная / «творческая» модель Gemini — этап description в AiFieldGeneratorJob.
 * Ключ и модель: GEMINI_PRO_API_KEY, GEMINI_CREATIVE_MODEL (config services.gemini_pro).
 */
class GeminiProService extends GeminiService
{
    public function __construct()
    {
        parent::__construct(
            configKeyPath: 'services.gemini_pro.key',
            configModelPath: 'services.gemini_pro.model',
            logTag: 'GeminiProService',
            missingKeyEnvHint: 'GEMINI_PRO_API_KEY',
            missingModelEnvHint: 'GEMINI_CREATIVE_MODEL',
        );
    }
}
