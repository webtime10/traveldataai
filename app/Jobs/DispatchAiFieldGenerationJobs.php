<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DispatchAiFieldGenerationJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    /**
     * @param  list<array{language_id:int,target_field:string,prompts:array<string,mixed>}>  $jobs
     */
    public function __construct(
        public Product $product,
        public array $jobs
    ) {}

    public function handle(): void
    {
        $product = $this->product->fresh();
        if (! $product) {
            throw new RuntimeException('Товар для запуска AI-задач не найден.');
        }

        $gist = trim((string) ($product->result ?? ''));
        if ($gist === '') {
            throw new RuntimeException('Выжимка products.result пуста — AI-задачи не запущены.');
        }

        $failedLanguages = Cache::get(GenerateAiDescriptionsBatchJob::failedLanguagesCacheKey($product->id), []);
        if (! is_array($failedLanguages)) {
            $failedLanguages = [];
        }

        $fullPipelineJobs = 0;

        foreach ($this->jobs as $job) {
            $languageId = (int) $job['language_id'];
            $targetField = (string) $job['target_field'];
            $skipDescriptionStage = true;

            if (in_array($languageId, $failedLanguages, true)) {
                $skipDescriptionStage = false;
                $fullPipelineJobs++;
            } else {
                $currentValue = DB::table('product_descriptions')
                    ->where('product_id', $product->id)
                    ->where('language_id', $languageId)
                    ->value($targetField);
                $currentText = trim(is_string($currentValue) ? $currentValue : (string) ($currentValue ?? ''));
                if ($currentText === '') {
                    $skipDescriptionStage = false;
                    $fullPipelineJobs++;
                }
            }

            dispatch(new AiFieldGeneratorJob(
                $product,
                $languageId,
                $targetField,
                '',
                (object) $job['prompts'],
                $skipDescriptionStage,
            ));
        }

        Log::info('[DispatchAiFieldGenerationJobs] AI-задачи запущены после пакетного description', [
            'product_id' => $product->id,
            'jobs_count' => count($this->jobs),
            'gist_len' => mb_strlen($gist),
            'failed_batch_languages' => $failedLanguages,
            'full_pipeline_jobs' => $fullPipelineJobs,
        ]);
    }
}
