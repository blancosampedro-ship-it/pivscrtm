<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea las 14 tablas legacy en BD de tests (SQLite memory).
 *
 * Schema mirror de producción MySQL latin1, simplificado para SQLite:
 * - `int` MySQL -> `integer` SQLite.
 * - `varchar(N)` -> `string` con length.
 * - `tinyint` -> `tinyInteger` para preservar semántica de valores 0/1/2.
 * - `timestamp default CURRENT_TIMESTAMP` -> `timestamp` con useCurrent().
 *
 * Esta migration NO se ejecuta en producción. Solo en tests vía
 * loadMigrationsFrom('database/migrations/legacy_test') en tests/TestCase.php.
 *
 * Schema verificado contra INFORMATION_SCHEMA prod 2026-04-30 (Bloque 02).
 * Ver ADRs 0006 (correctivo), 0007 (modulo+municipio), 0008 (auth fields).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- piv (575 filas en prod) ----------
        Schema::create('piv', function (Blueprint $t) {
            $t->integer('piv_id')->primary();
            $t->string('parada_cod', 255)->nullable();
            $t->string('cc_cod', 255)->nullable();
            $t->date('fecha_instalacion')->nullable();
            $t->string('n_serie_piv', 255)->nullable();
            $t->string('n_serie_sim', 255)->nullable();
            $t->string('n_serie_mgp', 255)->nullable();
            $t->string('tipo_piv', 255)->nullable();
            $t->string('tipo_marquesina', 255)->nullable();
            $t->string('tipo_alimentacion', 255)->nullable();
            $t->integer('industria_id')->nullable();
            $t->integer('concesionaria_id')->nullable();
            $t->string('direccion', 255)->nullable();
            $t->string('municipio', 255)->nullable();
            $t->tinyInteger('status')->nullable()->default(1);
            $t->integer('operador_id')->nullable();
            $t->integer('operador_id_2')->nullable();
            $t->integer('operador_id_3')->nullable();
            $t->string('prevision', 500)->nullable();
            $t->string('observaciones', 500)->nullable();
            $t->string('mantenimiento', 45)->nullable();
            $t->tinyInteger('status2')->nullable()->default(1);
        });

        // ---------- averia (66.392 filas en prod) ----------
        Schema::create('averia', function (Blueprint $t) {
            $t->unsignedInteger('averia_id')->primary();
            $t->integer('operador_id')->nullable();
            $t->integer('piv_id')->nullable();
            $t->string('notas', 500)->nullable();
            $t->timestamp('fecha')->nullable()->useCurrent();
            $t->tinyInteger('status')->nullable();
            $t->integer('tecnico_id')->nullable();
        });

        // ---------- asignacion (66.404 filas en prod) ----------
        Schema::create('asignacion', function (Blueprint $t) {
            $t->integer('asignacion_id')->primary();
            $t->integer('tecnico_id')->nullable();
            $t->date('fecha')->nullable();
            $t->integer('hora_inicial')->nullable();
            $t->integer('hora_final')->nullable();
            $t->tinyInteger('tipo')->nullable(); // 1=correctivo, 2=revisión
            $t->unsignedInteger('averia_id')->nullable();
            $t->tinyInteger('status')->nullable();
        });

        // ---------- correctivo (65.901 filas en prod) — ADR-0006 ----------
        Schema::create('correctivo', function (Blueprint $t) {
            $t->integer('correctivo_id')->primary();
            $t->integer('tecnico_id')->nullable();
            $t->integer('asignacion_id')->nullable();
            $t->string('tiempo', 45)->nullable();
            $t->tinyInteger('contrato')->nullable();
            $t->tinyInteger('facturar_horas')->nullable();
            $t->tinyInteger('facturar_desplazamiento')->nullable();
            $t->tinyInteger('facturar_recambios')->nullable();
            $t->string('recambios', 255)->nullable();
            $t->string('diagnostico', 255)->nullable();
            $t->string('estado_final', 100)->nullable();
        });

        // ---------- revision ----------
        Schema::create('revision', function (Blueprint $t) {
            $t->unsignedInteger('revision_id')->primary();
            $t->integer('tecnico_id')->nullable();
            $t->integer('asignacion_id')->nullable();
            $t->string('fecha', 100)->nullable();
            $t->string('ruta', 100)->nullable();
            $t->string('aspecto', 100)->nullable();
            $t->string('funcionamiento', 100)->nullable();
            $t->string('actuacion', 100)->nullable();
            $t->string('audio', 100)->nullable();
            $t->string('lineas', 100)->nullable();
            $t->string('fecha_hora', 100)->nullable();
            $t->string('precision_paso', 100)->nullable();
            $t->string('notas', 100)->nullable();
        });

        // ---------- tecnico (65 filas, 3 activos — RGPD sensitive) ----------
        Schema::create('tecnico', function (Blueprint $t) {
            $t->integer('tecnico_id')->primary();
            $t->string('usuario', 200)->nullable();
            $t->string('clave', 200)->nullable();      // SHA1 legacy — ADR-0008
            $t->string('email', 200)->nullable();
            $t->string('nombre_completo', 200)->nullable();
            $t->string('dni', 200)->nullable();
            $t->string('carnet_conducir', 200)->nullable();
            $t->string('direccion', 200)->nullable();
            $t->string('ccc', 200)->nullable();
            $t->string('n_seguridad_social', 200)->nullable();
            $t->string('telefono', 200)->nullable();
            $t->tinyInteger('status')->nullable()->default(1);
        });

        // ---------- operador (41 filas en prod) ----------
        Schema::create('operador', function (Blueprint $t) {
            $t->integer('operador_id')->primary();
            $t->string('usuario', 255)->nullable();
            $t->string('clave', 255)->nullable();      // SHA1 legacy — ADR-0008
            $t->string('email', 255)->nullable();
            $t->string('domicilio', 255)->nullable();
            $t->string('lineas', 255)->nullable();
            $t->string('responsable', 255)->nullable();
            $t->string('razon_social', 255)->nullable();
            $t->string('cif', 255)->nullable();
            $t->tinyInteger('status')->nullable()->default(1);
        });

        // ---------- modulo (catálogo polimórfico — ver ADR-0007) ----------
        Schema::create('modulo', function (Blueprint $t) {
            $t->integer('modulo_id')->primary();
            $t->string('nombre', 255)->nullable();
            $t->integer('tipo')->nullable();
        });

        // ---------- piv_imagen (1135 filas en prod) ----------
        Schema::create('piv_imagen', function (Blueprint $t) {
            $t->integer('piv_imagen_id')->primary();
            $t->integer('piv_id')->nullable();
            $t->string('url', 255)->nullable();
            $t->integer('posicion')->nullable();
        });

        // ---------- instalador_piv ----------
        Schema::create('instalador_piv', function (Blueprint $t) {
            $t->integer('instalador_piv_id')->primary();
            $t->integer('piv_id')->nullable();
            $t->integer('instalador_id')->nullable(); // FK lógica a u1.user_id
        });

        // ---------- desinstalado_piv ----------
        Schema::create('desinstalado_piv', function (Blueprint $t) {
            $t->unsignedInteger('desinstalado_piv_id')->primary();
            $t->integer('piv_id')->nullable();
            $t->string('observaciones', 500)->nullable();
            $t->integer('pos')->nullable();
        });

        // ---------- reinstalado_piv ----------
        Schema::create('reinstalado_piv', function (Blueprint $t) {
            $t->unsignedInteger('reinstalado_piv_id')->primary();
            $t->integer('piv_id')->nullable();
            $t->string('observaciones', 500)->nullable();
            $t->integer('pos')->nullable();
        });

        // ---------- u1 (1 fila admin — PK = user_id, excepción ADR-0008) ----------
        Schema::create('u1', function (Blueprint $t) {
            $t->integer('user_id')->primary();
            $t->string('username', 255)->nullable();
            $t->string('password', 255)->nullable();   // SHA1 legacy
            $t->string('email', 255)->nullable();
        });

        // ---------- session (legacy PHP — la nueva app no la toca) ----------
        Schema::create('session', function (Blueprint $t) {
            $t->string('session_id', 255)->primary();
            $t->string('user_id', 255)->nullable();
            $t->integer('rol')->nullable();
        });
    }

    public function down(): void
    {
        // Drop en orden inverso (sin FKs físicas, el orden no importa pero es prolijo).
        foreach ([
            'session', 'u1', 'reinstalado_piv', 'desinstalado_piv', 'instalador_piv',
            'piv_imagen', 'modulo', 'operador', 'tecnico', 'revision', 'correctivo',
            'asignacion', 'averia', 'piv',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
