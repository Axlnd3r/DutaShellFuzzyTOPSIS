<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pastikan tabel inti (atribut, atribut_value, kasus) ada.
 * Tabel ini mungkin sudah ada di database lama yang dibuat manual.
 * Migration ini hanya membuat jika belum ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('kasus')) {
            Schema::create('kasus', function (Blueprint $table) {
                $table->integer('case_num')->primary();
                $table->string('case_title', 200);
                $table->text('case_desc')->nullable();
            });
        }

        if (!Schema::hasTable('atribut')) {
            Schema::create('atribut', function (Blueprint $table) {
                $table->increments('atribut_id');
                $table->string('atribut_name', 200);
                $table->enum('goal', ['T', 'F'])->default('F');
                $table->string('atribut_desc', 250)->nullable();
                $table->integer('case_num')->nullable();
                $table->integer('user_id');
                $table->float('weight')->default(1.0)
                    ->comment('Bobot kriteria untuk Fuzzy TOPSIS');
                $table->string('type', 50)->default('benefit')
                    ->comment('Tipe kriteria: benefit atau cost');
                $table->index('user_id', 'idx_atribut_user');
                $table->index(['user_id', 'goal'], 'idx_atribut_goal');
            });
        }

        if (!Schema::hasTable('atribut_value')) {
            Schema::create('atribut_value', function (Blueprint $table) {
                $table->increments('value_id');
                $table->integer('atribut_id');
                $table->string('value_name', 200);
                $table->string('value_desc', 250)->nullable();
                $table->integer('user_id');
                $table->integer('case_num')->nullable();
                $table->index('atribut_id', 'idx_value_atribut');
                $table->index('user_id', 'idx_value_user');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atribut_value');
        Schema::dropIfExists('atribut');
        Schema::dropIfExists('kasus');
    }
};
