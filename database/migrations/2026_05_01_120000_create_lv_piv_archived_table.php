<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla nueva para registrar paneles "archivados" (soft-delete reversible).
 *
 * Schema según ADR-0012. Sin FK física a `piv` ni a `lv_users` (regla
 * coexistencia ADR-0002 + consistencia con resto de tablas lv_*).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_piv_archived', function (Blueprint $t) {
            $t->id();
            $t->integer('piv_id');                                  // FK lógica a piv.piv_id
            $t->timestamp('archived_at')->useCurrent();
            $t->unsignedBigInteger('archived_by_user_id')->nullable(); // FK lógica a lv_users.id
            $t->string('reason', 255)->nullable();
            $t->timestamps();

            $t->unique('piv_id', 'uniq_piv_archived');
            $t->index('archived_at', 'idx_archived_at');
            $t->index('archived_by_user_id', 'idx_archived_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_piv_archived');
    }
};
