<?php

declare(strict_types=1);

use App\Models\LvDerivacion;
use App\Models\LvRutaDiaItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lv_ruta_dia_item', function (Blueprint $table): void {
            $table->enum('status', LvRutaDiaItem::STATUSES)
                ->default(LvRutaDiaItem::STATUS_PENDIENTE)
                ->change();
        });

        Schema::create('lv_derivacion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lv_ruta_dia_item_id')->constrained('lv_ruta_dia_item')->cascadeOnDelete();
            $table->string('tipo_causa', 40);
            $table->string('causa_otros_texto', 500)->nullable();
            $table->string('actor_responsable', 40);
            $table->string('actor_notas', 200)->nullable();
            $table->text('notas_derivacion')->nullable();
            $table->dateTime('fecha_derivacion');
            $table->unsignedBigInteger('derivado_por_user_id')->nullable();
            $table->string('status', 30)->default(LvDerivacion::STATUS_PENDIENTE_TERCERO);
            $table->dateTime('fecha_resolucion')->nullable();
            $table->text('resuelto_notas')->nullable();
            $table->unsignedBigInteger('resuelto_por_user_id')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_derivacion_status');
            $table->index('tipo_causa', 'idx_derivacion_tipo_causa');
            $table->index('actor_responsable', 'idx_derivacion_actor');
            $table->index('fecha_derivacion', 'idx_derivacion_fecha');
            $table->index(['lv_ruta_dia_item_id', 'status'], 'idx_derivacion_item_status');

            // MySQL no soporta índice único parcial portable para status abiertas;
            // la unicidad de derivación abierta por item se garantiza en DerivacionService.
            $table->foreign('derivado_por_user_id', 'fk_derivacion_derivado_por')
                ->references('id')->on('lv_users')
                ->nullOnDelete();
            $table->foreign('resuelto_por_user_id', 'fk_derivacion_resuelto_por')
                ->references('id')->on('lv_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_derivacion');

        Schema::table('lv_ruta_dia_item', function (Blueprint $table): void {
            $table->enum('status', [
                LvRutaDiaItem::STATUS_PENDIENTE,
                LvRutaDiaItem::STATUS_EN_PROGRESO,
                LvRutaDiaItem::STATUS_CERRADO,
                LvRutaDiaItem::STATUS_NO_RESUELTO,
            ])->default(LvRutaDiaItem::STATUS_PENDIENTE)->change();
        });
    }
};
