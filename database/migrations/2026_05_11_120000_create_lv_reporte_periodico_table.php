<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_reporte_periodico', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo', 20);
            $table->unsignedSmallInteger('anyo');
            $table->unsignedTinyInteger('mes')->nullable();
            $table->dateTime('generated_at');
            $table->unsignedBigInteger('generated_by_user_id')->nullable();
            $table->string('pdf_path');
            $table->string('xlsx_path');
            $table->json('metadata_json');
            $table->timestamps();

            $table->unique(['tipo', 'anyo', 'mes'], 'uniq_reporte_periodo');
            $table->index(['tipo', 'anyo', 'mes'], 'idx_reporte_periodo');
            $table->index('generated_at', 'idx_reporte_generated_at');

            $table->foreign('generated_by_user_id', 'fk_reporte_generated_by')
                ->references('id')->on('lv_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_reporte_periodico');
    }
};
