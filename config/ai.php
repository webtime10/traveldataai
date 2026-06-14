<?php

return [

/*
| Языки для проверки готовности AI — из таблицы languages (Language::codesForAiChecks).
| В запросе checkAiStatus можно передать ?languages=en,he,ar
*/
    'generation' => [
        // Опрос checkAiStatus / UI: полный прогон (выжимка + batch + этапы 2–3) может идти 1–2+ ч.
        'timeout_seconds' => (int) env('AI_GENERATION_TIMEOUT', 7200),
        /**
         * Laravel queue:work --timeout и $job->timeout для Extract/GenerateAiDescriptionsBatch.
         * Должен быть >= AI_GENERATION_TIMEOUT и >= GEMINI_EXTRACTION_TIMEOUT.
         */
        'queue_worker_timeout' => (int) env('QUEUE_WORKER_TIMEOUT', 7200),
    ],

];
