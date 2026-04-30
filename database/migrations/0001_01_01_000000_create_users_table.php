<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // lv_users: auth unificado de los 3 roles legacy (u1, tecnico, operador).
        // Schema según ADR-0005: lookup canónico por (legacy_kind, legacy_id),
        // email no único (puede haber colisión cross-tabla, ya verificado en
        // Bloque 02). Password nullable hasta primer login post-migración (ADR-0003).
        Schema::create('lv_users', function (Blueprint $t) {
            $t->id();
            $t->enum('legacy_kind', ['admin', 'tecnico', 'operador']);
            $t->unsignedInteger('legacy_id');
            $t->string('email');
            $t->string('name');
            $t->string('password')->nullable();                    // bcrypt; null hasta primer login
            $t->char('legacy_password_sha1', 40)->nullable();      // copia del SHA1 legacy; se borra al rehash
            $t->timestamp('lv_password_migrated_at')->nullable();
            $t->rememberToken();
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamps();

            $t->unique(['legacy_kind', 'legacy_id'], 'uniq_legacy');
            $t->index('email', 'idx_email');
        });

        Schema::create('lv_password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('lv_sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_sessions');
        Schema::dropIfExists('lv_password_reset_tokens');
        Schema::dropIfExists('lv_users');
    }
};
