<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('lv_piv_zona_municipio');
        Schema::dropIfExists('lv_piv_zona');
    }

    public function down(): void
    {
        Schema::create('lv_piv_zona', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 80)->unique();
            $table->string('color_hint', 7)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lv_piv_zona_municipio', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zona_id')->constrained('lv_piv_zona')->cascadeOnDelete();
            $table->unsignedInteger('municipio_modulo_id');
            $table->timestamps();

            $table->unique('municipio_modulo_id', 'idx_municipio_unique_zona');
            $table->index('zona_id', 'idx_zona_id');
        });
    }
};
