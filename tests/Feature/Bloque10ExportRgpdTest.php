<?php

declare(strict_types=1);

use App\Models\Tecnico;
use App\Support\TecnicoExportTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('tecnico_export_blacklist', function () {
    $tecnico = Tecnico::factory()->create([
        'nombre_completo' => 'Juan Pérez',
        'dni' => '12345678A',
        'n_seguridad_social' => '281234567890',
        'ccc' => '281234567890',
        'telefono' => '600123456',
        'direccion' => 'Calle Falsa 123',
        'email' => 'juan@example.com',
        'carnet_conducir' => 'B12345678',
    ]);

    $exported = TecnicoExportTransformer::forOperador($tecnico);

    foreach (TecnicoExportTransformer::BLACKLIST_FIELDS_FOR_OPERADOR as $field) {
        expect($exported)->not->toHaveKey($field);
    }
});

it('tecnico_export_includes_nombre_completo', function () {
    $tecnico = Tecnico::factory()->create(['nombre_completo' => 'Juan Pérez']);

    $exported = TecnicoExportTransformer::forOperador($tecnico);

    expect($exported)->toHaveKey('tecnico_nombre');
    expect($exported['tecnico_nombre'])->toBe('Juan Pérez');
});

it('tecnico_export_admin_includes_all_fields — regression guard against blacklist leaking to admin path', function () {
    $tecnico = Tecnico::factory()->create([
        'nombre_completo' => 'Juan Pérez',
        'dni' => '12345678A',
        'email' => 'juan@example.com',
        'telefono' => '600123456',
    ]);

    $exported = TecnicoExportTransformer::forAdmin($tecnico);

    expect($exported)->toHaveKey('dni')->and($exported['dni'])->toBe('12345678A');
    expect($exported)->toHaveKey('email')->and($exported['email'])->toBe('juan@example.com');
    expect($exported)->toHaveKey('telefono')->and($exported['telefono'])->toBe('600123456');
    expect($exported)->toHaveKey('nombre_completo')->and($exported['nombre_completo'])->toBe('Juan Pérez');
});

it('tecnico_export_handles_null_tecnico — forOperador and forAdmin tolerate null', function () {
    $shapedOp = TecnicoExportTransformer::forOperador(null);
    expect($shapedOp)->toBeArray()->toHaveKey('tecnico_nombre');
    expect($shapedOp['tecnico_nombre'])->toBeNull();

    $shapedAdm = TecnicoExportTransformer::forAdmin(null);
    expect($shapedAdm)->toBeArray()->toHaveKey('nombre_completo');
});
