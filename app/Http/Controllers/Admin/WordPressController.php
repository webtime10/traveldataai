<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\WpPublishJob;
use App\Models\Category;
use App\Models\CategoryDescription;
use App\Models\Language;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductDescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WordPressController extends Controller
{
    private function persistWpPublishedState(Product $product): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }
        if (! Schema::hasColumn('products', 'wp_published_once')) {
            return;
        }

        $payload = [
            'wp_published_once' => true,
        ];
        if (Schema::hasColumn('products', 'wp_last_published_at')) {
            $payload['wp_last_published_at'] = now();
        }

        $product->forceFill($payload)->save();
    }

    public function publish(Product $product): JsonResponse
    {
        $hasData = DB::table('product_descriptions')->where('product_id', $product->id)->exists();
//  ->exists(); проверка есть ли чтот в базе данных
        if (! $hasData) {
            return response()->json(['message' => 'No data to publish.'], 400);
        }

        try {
            // Для кнопки "Опубликовать в WordPress" выполняем сразу,
            // чтобы не зависеть от состояния queue worker (pcntl и т.п.).
            WpPublishJob::dispatchSync($product);
            $this->persistWpPublishedState($product);

            return response()->json([
                'status' => 'sent',
                'message' => 'Published to WordPress',
                'wp_published_once' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('[WordPressController] Publish failed', [
                'product_id' => $product->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Publish failed: '.$e->getMessage(),
            ], 500);
        }

        // проверяю есть ли что-то в базе данных отправляю в очередь (воркер) выдаю сообщение 
        //  'status' => 'queued',
        // 'message' => 'Publish queued', успешно в очереди

    }

    public function publishField(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'ai_field' => ['required', 'string'],
        ]);

        $aiField = (string) $data['ai_field'];
        if (! in_array($aiField, ProductDescription::aiFieldKeys(), true)) {
            return response()->json(['message' => 'Invalid ai_field.'], 422);
        }

        try {
            WpPublishJob::dispatchSync($product, [$aiField], true);
            $this->persistWpPublishedState($product);

            return response()->json([
                'status' => 'sent',
                'message' => 'Field published to WordPress',
                'ai_field' => $aiField,
                'wp_published_once' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('[WordPressController] Field publish failed', [
                'product_id' => $product->id,
                'ai_field' => $aiField,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Field publish failed: '.$e->getMessage(),
            ], 500);
        }
    }
}
