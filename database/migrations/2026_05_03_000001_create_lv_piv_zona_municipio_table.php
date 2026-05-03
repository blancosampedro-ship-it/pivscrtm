<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot zona <-> municipio. Sin FK física a `modulo` (regla coexistencia
 * ADR-0002): la integridad la valida la app. Un municipio en exactamente
 * UNA zona (UNIQUE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_piv_zona_municipio', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zona_id')->constrained('lv_piv_zona')->cascadeOnDelete();
            $table->unsignedInteger('municipio_modulo_id')->comment('FK lógica a modulo.modulo_id donde tipo=5');
            $table->timestamps();

            $table->unique('municipio_modulo_id', 'idx_municipio_unique_zona');
            $table->index('zona_id', 'idx_zona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_piv_zona_municipio');
    }
};
