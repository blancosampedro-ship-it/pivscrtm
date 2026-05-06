<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía panel_id_sgip de varchar(20) a varchar(100).
 *
 * Bug detectado durante smoke real prod 12d (6 may 2026): el campo "Resumen"
 * del CSV SGIP NO sigue formato "PANEL XXXXX" (5 dígitos), sino "PANEL <id>
 * <NOMBRE_LOCALIDAD>" con longitud variable hasta 39 chars (ej "PANEL 17474B
 * SAN SEBASTIAN DE LOS REYES"). El varchar(20) original truncaba con
 * SQLSTATE[22001] al insertar. Ampliamos a 100 con margen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lv_averia_icca', function (Blueprint $table): void {
            $table->string('panel_id_sgip', 100)
                ->comment('Resumen CSV raw, ej "PANEL 17474B SAN SEBASTIAN DE LOS REYES"')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('lv_averia_icca', function (Blueprint $table): void {
            $table->string('panel_id_sgip', 20)
                ->comment('Resumen CSV raw, ej "PANEL 18484"')
                ->change();
        });
    }
};
