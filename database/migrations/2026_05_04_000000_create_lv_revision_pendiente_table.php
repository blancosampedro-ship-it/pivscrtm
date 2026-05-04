<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_revision_pendiente', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('piv_id')->comment('FK logica a piv.piv_id sin constraint fisico, ADR-0002');
            $table->unsignedSmallInteger('periodo_year');
            $table->unsignedTinyInteger('periodo_month');
            $table->enum('status', [
                'pendiente',
                'verificada_remoto',
                'requiere_visita',
                'excepcion',
                'completada',
            ])->default('pendiente');
            $table->date('fecha_planificada')->nullable()->comment('Set solo cuando status=requiere_visita en 12b.4');
            $table->unsignedBigInteger('decision_user_id')->nullable();
            $table->timestamp('decision_at')->nullable();
            $table->text('decision_notas')->nullable();
            $table->unsignedBigInteger('carry_over_origen_id')->nullable()->comment('Self-FK a fila del mes anterior si vino por carry');
            $table->unsignedInteger('asignacion_id')->nullable()->comment('FK logica a asignacion.asignacion_id legacy, set por 12b.4');
            $table->timestamps();

            $table->unique(['piv_id', 'periodo_year', 'periodo_month'], 'uniq_piv_periodo');
            $table->index('status', 'idx_status');
            $table->index(['periodo_year', 'periodo_month'], 'idx_periodo');
            $table->index('fecha_planificada', 'idx_fecha_planificada');
            $table->index('asignacion_id', 'idx_asignacion_id');

            $table->foreign('carry_over_origen_id', 'fk_carry_over_origen')
                ->references('id')->on('lv_revision_pendiente')
                ->nullOnDelete();
            $table->foreign('decision_user_id', 'fk_decision_user')
                ->references('id')->on('lv_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_revision_pendiente');
    }
};
