<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_ruta_dia', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('tecnico_id')->comment('FK logica a tecnico legacy (sin constraint fisico, ADR-0002)');
            $table->date('fecha');
            $table->enum('status', ['planificada', 'en_progreso', 'completada', 'cancelada'])->default('planificada');
            $table->text('notas_admin')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['tecnico_id', 'fecha'], 'uniq_tecnico_fecha');
            $table->index('fecha', 'idx_fecha');
            $table->index('status', 'idx_ruta_dia_status');

            $table->foreign('created_by_user_id', 'fk_ruta_dia_created_by')
                ->references('id')->on('lv_users')
                ->nullOnDelete();
        });

        Schema::create('lv_ruta_dia_item', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ruta_dia_id')->constrained('lv_ruta_dia')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden');
            $table->enum('tipo_item', ['correctivo', 'preventivo', 'carry_over']);
            $table->unsignedBigInteger('lv_averia_icca_id')->nullable()->comment('FK logica si tipo=correctivo');
            $table->unsignedBigInteger('lv_revision_pendiente_id')->nullable()->comment('FK logica si tipo=preventivo|carry_over');
            $table->enum('status', ['pendiente', 'en_progreso', 'cerrado', 'no_resuelto'])->default('pendiente');
            $table->text('causa_no_resolucion')->nullable()->comment('Set por tecnico en 12g si no puede resolver');
            $table->text('notas_tecnico')->nullable();
            $table->timestamp('cerrado_at')->nullable();
            $table->timestamps();

            $table->index(['ruta_dia_id', 'orden'], 'idx_ruta_orden');
            $table->index('status', 'idx_ruta_dia_item_status');
            $table->index('lv_averia_icca_id', 'idx_averia_icca');
            $table->index('lv_revision_pendiente_id', 'idx_revision_pendiente');

            $table->foreign('lv_averia_icca_id', 'fk_item_averia_icca')
                ->references('id')->on('lv_averia_icca')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_ruta_dia_item');
        Schema::dropIfExists('lv_ruta_dia');
    }
};
