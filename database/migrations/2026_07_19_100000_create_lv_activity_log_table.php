<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2 — Registro de auditoría (spatie/laravel-activitylog v4).
 *
 * Consolida en una sola migración los 3 stubs del paquete (create + event +
 * batch_uuid) con el nombre de tabla del proyecto (`lv_activity_log`, ver
 * config/activitylog.php). Registra quién hizo qué en el panel: altas,
 * ediciones y borrados de los modelos clave, con usuario causante y diff
 * de atributos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('activitylog.table_name'), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();

            $table->index('log_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('activitylog.table_name'));
    }
};
