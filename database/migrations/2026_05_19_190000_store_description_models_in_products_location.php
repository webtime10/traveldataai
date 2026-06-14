<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->json('location')->nullable()->change();
        });

        if (Schema::hasColumn('products', 'description_models')) {
            $rows = DB::table('products')
                ->whereNotNull('description_models')
                ->select('id', 'description_models', 'location')
                ->get();

            foreach ($rows as $row) {
                if ($row->location !== null && $row->location !== '') {
                    continue;
                }
                DB::table('products')->where('id', $row->id)->update([
                    'location' => $row->description_models,
                ]);
            }

            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('description_models');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (! Schema::hasColumn('products', 'description_models')) {
            Schema::table('products', function (Blueprint $table) {
                $table->json('description_models')->nullable()->after('ai_status');
            });
        }

        $rows = DB::table('products')->whereNotNull('location')->select('id', 'location')->get();
        foreach ($rows as $row) {
            DB::table('products')->where('id', $row->id)->update([
                'description_models' => $row->location,
            ]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('location', 128)->nullable()->change();
        });
    }
};
