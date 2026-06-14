<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Восемь полей сырья: source_text1 … source_text8 в таблице products.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'source_text1',
        'source_text2',
        'source_text3',
        'source_text4',
        'source_text5',
        'source_text6',
        'source_text7',
        'source_text8',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (Schema::hasColumn('products', 'source_text') && ! Schema::hasColumn('products', 'source_text1')) {
            $driver = Schema::getConnection()->getDriverName();

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE `products` CHANGE `source_text` `source_text1` LONGTEXT NULL');
            } else {
                Schema::table('products', function (Blueprint $table) {
                    $table->renameColumn('source_text', 'source_text1');
                });
            }
        }

        $after = 'author_id';
        if (Schema::hasColumn('products', 'source_text1')) {
            $after = 'source_text1';
        } elseif (Schema::hasColumn('products', 'source_text')) {
            $after = 'source_text';
        }

        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('products', $column)) {
                $after = $column;

                continue;
            }

            Schema::table('products', function (Blueprint $table) use ($column, $after) {
                $table->longText($column)->nullable()->after($after);
            });
            $after = $column;
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (array_reverse(self::COLUMNS) as $column) {
                if ($column === 'source_text1') {
                    continue;
                }
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('products', 'source_text1') && ! Schema::hasColumn('products', 'source_text')) {
            $driver = Schema::getConnection()->getDriverName();

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE `products` CHANGE `source_text1` `source_text` LONGTEXT NULL');
            } else {
                Schema::table('products', function (Blueprint $table) {
                    $table->renameColumn('source_text1', 'source_text');
                });
            }
        }
    }
};
