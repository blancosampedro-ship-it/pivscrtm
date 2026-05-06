<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_ruta_dia_item_imagen', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ruta_dia_item_id')->constrained('lv_ruta_dia_item')->cascadeOnDelete();
            $table->string('url', 500)->comment('Path en disk public, ej piv-images/ruta-dia-item/abc.jpg');
            $table->unsignedSmallInteger('posicion')->default(1);
            $table->timestamps();

            $table->index(['ruta_dia_item_id', 'posicion'], 'idx_item_posicion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_ruta_dia_item_imagen');
    }
};
