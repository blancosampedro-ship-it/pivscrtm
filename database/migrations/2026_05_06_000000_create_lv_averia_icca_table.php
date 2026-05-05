<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_averia_icca', function (Blueprint $table): void {
            $table->id();
            $table->string('sgip_id', 20)->unique()->comment('Id del CSV SGIP, ej "0028078"');
            $table->string('panel_id_sgip', 20)->comment('Resumen CSV raw, ej "PANEL 18484"');
            $table->unsignedInteger('piv_id')->nullable()->comment('Match resolved con piv.parada_cod, NULL si ambiguo o no-match');
            $table->string('categoria', 80)->comment('Problemas de comunicación / Panel apagado / Problema de tiempos / Problema de audio / otras');
            $table->text('descripcion')->nullable();
            $table->mediumText('notas')->nullable()->comment('Hilo histórico CAU_ICCA/SGIP_winfin/SGIP_Indra con timestamps');
            $table->string('estado_externo', 30)->comment('Estado CSV, típicamente asignada');
            $table->string('asignada_a', 30)->comment('Asignada a CSV, típicamente SGIP_winfin');
            $table->boolean('activa')->default(true);
            $table->timestamp('fecha_import')->comment('Cuando admin subió este CSV');
            $table->string('archivo_origen', 255)->comment('Filename del CSV subido');
            $table->unsignedBigInteger('imported_by_user_id')->nullable();
            $table->timestamp('marked_inactive_at')->nullable()->comment('Cuando dejó de aparecer en CSV nuevo');
            $table->timestamps();

            $table->index(['piv_id', 'activa'], 'idx_piv_activa');
            $table->index('categoria', 'idx_categoria');
            $table->index(['activa', 'fecha_import'], 'idx_activa_fecha');

            $table->foreign('imported_by_user_id', 'fk_averia_icca_imported_by')
                ->references('id')->on('lv_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_averia_icca');
    }
};
