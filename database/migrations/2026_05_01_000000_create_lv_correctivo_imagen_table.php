<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla nueva para fotos asociadas al cierre de un correctivo.
 *
 * Schema según ADR-0006 (correctivo schema reuse strategy). NO va en la
 * tabla legacy `correctivo` (que no tiene columna `imagen`) ni se mete en
 * `piv_imagen` (que es por panel, no por cierre concreto).
 *
 * Sin FK física a `correctivo` (regla coexistencia ADR-0002: no constraints
 * que apunten a tablas legacy). La integridad referencial se valida en app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_correctivo_imagen', function (Blueprint $t) {
            $t->id();
            $t->integer('correctivo_id');                          // FK lógica a correctivo.correctivo_id
            $t->string('url', 500);                                // path en storage/app/public/piv-images/correctivo/
            $t->unsignedTinyInteger('posicion')->default(1);       // orden de las fotos del cierre
            $t->timestamps();

            $t->index('correctivo_id', 'idx_correctivo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_correctivo_imagen');
    }
};
