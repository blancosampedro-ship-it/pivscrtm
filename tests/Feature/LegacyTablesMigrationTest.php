<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the 14 legacy tables in SQLite test DB', function () {
    foreach ([
        'piv', 'averia', 'asignacion', 'correctivo', 'revision',
        'tecnico', 'operador', 'modulo', 'piv_imagen',
        'instalador_piv', 'desinstalado_piv', 'reinstalado_piv',
        'u1', 'session',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Tabla {$table} no existe en BD test");
    }
});

it('piv has correct primary key piv_id', function () {
    expect(Schema::hasColumn('piv', 'piv_id'))->toBeTrue();
});

it('u1 has user_id as primary key (ADR-0008 excepción)', function () {
    expect(Schema::hasColumn('u1', 'user_id'))->toBeTrue();
    expect(Schema::hasColumn('u1', 'u1_id'))->toBeFalse();
});

it('tecnico password column is clave (ADR-0008)', function () {
    expect(Schema::hasColumn('tecnico', 'clave'))->toBeTrue();
    expect(Schema::hasColumn('tecnico', 'password'))->toBeFalse();
});

it('operador password column is clave (ADR-0008)', function () {
    expect(Schema::hasColumn('operador', 'clave'))->toBeTrue();
    expect(Schema::hasColumn('operador', 'password'))->toBeFalse();
});

it('correctivo lacks accion/imagen (ADR-0006)', function () {
    expect(Schema::hasColumn('correctivo', 'diagnostico'))->toBeTrue();
    expect(Schema::hasColumn('correctivo', 'recambios'))->toBeTrue();
    expect(Schema::hasColumn('correctivo', 'estado_final'))->toBeTrue();
    expect(Schema::hasColumn('correctivo', 'accion'))->toBeFalse();
    expect(Schema::hasColumn('correctivo', 'imagen'))->toBeFalse();
});
