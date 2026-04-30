<?php

declare(strict_types=1);

use App\Models\Modulo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('loads a modulo row with its tipo and nombre', function () {
    $m = Modulo::factory()->create(['nombre' => 'Madrid', 'tipo' => 5]);
    $found = Modulo::find($m->modulo_id);

    expect($found)->not->toBeNull();
    expect($found->nombre)->toBe('Madrid');
    expect($found->tipo)->toBe(5);
});

it('scopeMunicipios filters by tipo=5', function () {
    Modulo::factory()->create(['tipo' => Modulo::TIPO_INDUSTRIA]);
    Modulo::factory()->create(['tipo' => Modulo::TIPO_MUNICIPIO]);
    Modulo::factory()->create(['tipo' => Modulo::TIPO_MUNICIPIO]);

    expect(Modulo::municipios()->count())->toBe(2);
});

it('scopeChecks filters by tipos 9-14', function () {
    Modulo::factory()->create(['tipo' => Modulo::TIPO_INDUSTRIA]);
    Modulo::factory()->create(['tipo' => Modulo::TIPO_CHECK_ASPECTO]);
    Modulo::factory()->create(['tipo' => Modulo::TIPO_CHECK_AUDIO]);

    expect(Modulo::checks()->count())->toBe(2);
});

it('Latin1String cast applied on nombre roundtrips Spanish chars', function () {
    $m = Modulo::factory()->create(['nombre' => 'Alcalá de Henares']);
    $m->refresh();

    expect($m->nombre)->toBe('Alcalá de Henares');
});

it('exposes type constants matching legacy values (ADR-0007)', function () {
    expect(Modulo::TIPO_MUNICIPIO)->toBe(5);
    expect(Modulo::TIPO_INDUSTRIA)->toBe(1);
    expect(Modulo::TIPO_CHECK_ASPECTO)->toBe(9);
    expect(Modulo::TIPO_CHECK_PRECISION_PASO)->toBe(14);
});
