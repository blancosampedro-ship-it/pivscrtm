<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Correctivo;
use App\Models\Operador;
use App\Models\Piv;
use App\Models\Revision;
use App\Models\Tecnico;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('Averia resolves piv, operador and tecnico relations', function () {
    Piv::factory()->create(['piv_id' => 200]);
    Operador::factory()->create(['operador_id' => 10]);
    Tecnico::factory()->create(['tecnico_id' => 3]);
    $a = Averia::factory()->create([
        'averia_id' => 1,
        'piv_id' => 200,
        'operador_id' => 10,
        'tecnico_id' => 3,
        'notas' => 'Pantalla apagada en Móstoles',
    ]);
    $a->refresh();

    expect($a->piv->piv_id)->toBe(200);
    expect($a->operador->operador_id)->toBe(10);
    expect($a->tecnico->tecnico_id)->toBe(3);
    expect($a->notas)->toBe('Pantalla apagada en Móstoles');
});

it('Asignacion exposes TIPO_CORRECTIVO and TIPO_REVISION constants', function () {
    expect(Asignacion::TIPO_CORRECTIVO)->toBe(1);
    expect(Asignacion::TIPO_REVISION)->toBe(2);
});

it('Asignacion piv accessor walks through averia.piv', function () {
    Piv::factory()->create(['piv_id' => 300]);
    Averia::factory()->create(['averia_id' => 2, 'piv_id' => 300]);
    $asig = Asignacion::factory()->create(['asignacion_id' => 1, 'averia_id' => 2]);

    expect($asig->piv)->not->toBeNull();
    expect($asig->piv->piv_id)->toBe(300);
});

it('Asignacion has hasOne relations to correctivo and revision', function () {
    $asig = Asignacion::factory()->create(['asignacion_id' => 5]);
    Correctivo::factory()->create(['asignacion_id' => 5, 'diagnostico' => 'OK']);

    expect($asig->correctivo)->not->toBeNull();
    expect($asig->correctivo->diagnostico)->toBe('OK');
});

it('Correctivo casts facturacion flags as boolean', function () {
    $c = Correctivo::factory()->create([
        'contrato' => 1,
        'facturar_horas' => 0,
        'facturar_recambios' => 1,
    ]);
    $c->refresh();

    expect($c->contrato)->toBeTrue();
    expect($c->facturar_horas)->toBeFalse();
    expect($c->facturar_recambios)->toBeTrue();
});

it('Correctivo Latin1String cast on diagnostico and recambios', function () {
    $c = Correctivo::factory()->create([
        'diagnostico' => 'Cambio de SIM por avería en módem',
        'recambios' => 'SIM nueva, módem revisado',
    ]);
    $c->refresh();

    expect($c->diagnostico)->toBe('Cambio de SIM por avería en módem');
    expect($c->recambios)->toBe('SIM nueva, módem revisado');
});

it('Revision allows null notas (red-line: nunca autofill REVISION MENSUAL)', function () {
    $r = Revision::factory()->create(['notas' => null]);
    $r->refresh();

    expect($r->notas)->toBeNull();
});

it('Revision Latin1String cast on notas roundtrips Spanish', function () {
    $r = Revision::factory()->create(['notas' => 'Pantalla revisada en Cádiz']);
    $r->refresh();

    expect($r->notas)->toBe('Pantalla revisada en Cádiz');
});
