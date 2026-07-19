<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1 — Cierre correctivo completo (auditoría jul-2026).
 *
 * Marca de "reparada localmente" en lv_averia_icca: la pone el cierre del item
 * de ruta cuando el técnico resuelve el correctivo. Es PARALELA a `activa`
 * (que sigue siendo verdad-SGIP vía import): una ICCA puede seguir activa en
 * SGIP pero estar reparada localmente → no debe volver a planificarse.
 *
 * Solo tabla lv_* propia — cero ALTER sobre tablas legacy (ADR-0002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lv_averia_icca', function (Blueprint $table): void {
            // Cuándo la reparó el técnico en campo. NULL = sin cierre local.
            $table->timestamp('cerrada_local_at')->nullable()->after('marked_inactive_at');
            // FK lógica al lv_ruta_dia_item que la cerró (sin constraint, como
            // lv_revision_pendiente.asignacion_id — evita FKs cruzadas item↔icca).
            $table->unsignedBigInteger('cerrada_por_item_id')->nullable()->after('cerrada_local_at');

            $table->index('cerrada_local_at', 'idx_icca_cerrada_local');
        });
    }

    public function down(): void
    {
        Schema::table('lv_averia_icca', function (Blueprint $table): void {
            $table->dropIndex('idx_icca_cerrada_local');
            $table->dropColumn(['cerrada_local_at', 'cerrada_por_item_id']);
        });
    }
};
