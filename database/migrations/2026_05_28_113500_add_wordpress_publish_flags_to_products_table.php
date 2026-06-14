<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'wp_published_once')) {
                $table->boolean('wp_published_once')->default(false)->after('ai_status');
            }
            if (! Schema::hasColumn('products', 'wp_last_published_at')) {
                $table->timestamp('wp_last_published_at')->nullable()->after('wp_published_once');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'wp_last_published_at')) {
                $table->dropColumn('wp_last_published_at');
            }
            if (Schema::hasColumn('products', 'wp_published_once')) {
                $table->dropColumn('wp_published_once');
            }
        });
    }
};
