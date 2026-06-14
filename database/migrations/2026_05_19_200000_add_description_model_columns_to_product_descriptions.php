<?php

use App\Models\ProductDescription;
use App\Support\AiDescriptionModelChoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_descriptions')) {
            return;
        }

        Schema::table('product_descriptions', function (Blueprint $table) {
            foreach (ProductDescription::aiFieldKeys() as $field) {
                $column = AiDescriptionModelChoice::modelColumnForField($field);
                if (! Schema::hasColumn('product_descriptions', $column)) {
                    $table->string($column, 32)->nullable()->after($field);
                }
            }
        });

        $this->migrateFromProductsLocation();
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_descriptions')) {
            return;
        }

        Schema::table('product_descriptions', function (Blueprint $table) {
            foreach (ProductDescription::aiFieldKeys() as $field) {
                $column = AiDescriptionModelChoice::modelColumnForField($field);
                if (Schema::hasColumn('product_descriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function migrateFromProductsLocation(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'location')) {
            return;
        }

        $rows = DB::table('products')->whereNotNull('location')->get(['id', 'location']);
        foreach ($rows as $row) {
            $settings = json_decode((string) $row->location, true);
            if (! is_array($settings)) {
                continue;
            }

            $payload = [];
            foreach ($settings as $field => $modelKey) {
                if (! is_string($field) || ! is_string($modelKey)) {
                    continue;
                }
                $column = AiDescriptionModelChoice::modelColumnForField($field);
                if (Schema::hasColumn('product_descriptions', $column)) {
                    $payload[$column] = $modelKey;
                }
            }

            if ($payload !== []) {
                DB::table('product_descriptions')
                    ->where('product_id', $row->id)
                    ->update($payload);
            }
        }
    }
};
