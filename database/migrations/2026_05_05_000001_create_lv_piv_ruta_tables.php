<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_piv_ruta', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 12)->unique()->comment('ROSA-NO, ROSA-E, VERDE, AZUL, AMARILLO');
            $table->string('nombre', 80)->unique()->comment('Rosa Noroeste, Rosa Este, ...');
            $table->string('zona_geografica', 120)->nullable()->comment('Sierra de Guadarrama / Cuenca Alta del Manzanares');
            $table->string('color_hint', 7)->nullable()->comment('Hex Carbon-aligned');
            $table->unsignedSmallInteger('km_medio')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lv_piv_ruta_municipio', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ruta_id')->constrained('lv_piv_ruta')->cascadeOnDelete();
            $table->unsignedInteger('municipio_modulo_id')->comment('FK lógica modulo.modulo_id tipo=5 (sin constraint físico, ADR-0002)');
            $table->unsignedSmallInteger('km_desde_ciempozuelos')->nullable()->comment('Del Excel Maestro Municipios');
            $table->timestamps();

            $table->unique('municipio_modulo_id', 'idx_municipio_unique_ruta');
            $table->index('ruta_id', 'idx_ruta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_piv_ruta_municipio');
        Schema::dropIfExists('lv_piv_ruta');
    }
};
