<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_piv_zona', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 80)->unique();
            $table->string('color_hint', 7)->nullable()->comment('Hex color para el calendario operacional UI');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_piv_zona');
    }
};
