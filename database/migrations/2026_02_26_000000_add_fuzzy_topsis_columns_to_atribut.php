<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahkan kolom weight dan type ke tabel atribut
 * untuk mendukung algoritma Fuzzy TOPSIS.
 *
 * weight = bobot kepentingan kriteria (default 1.0)
 * type   = 'benefit' (max = baik) atau 'cost' (min = baik)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('atribut')) {
            return;
        }

        if (!Schema::hasColumn('atribut', 'weight')) {
            Schema::table('atribut', function (Blueprint $table) {
                $table->float('weight')->default(1.0)
                    ->after('user_id')
                    ->comment('Bobot kriteria untuk Fuzzy TOPSIS');
            });
        }

        if (!Schema::hasColumn('atribut', 'type')) {
            Schema::table('atribut', function (Blueprint $table) {
                $table->string('type', 50)->default('benefit')
                    ->after('weight')
                    ->comment('Tipe kriteria: benefit atau cost');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('atribut')) {
            return;
        }

        Schema::table('atribut', function (Blueprint $table) {
            if (Schema::hasColumn('atribut', 'weight')) {
                $table->dropColumn('weight');
            }
            if (Schema::hasColumn('atribut', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
