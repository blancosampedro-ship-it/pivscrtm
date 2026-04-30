<?php

declare(strict_types=1);

use App\Models\Operador;
use App\Models\Piv;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses piv_id as primary key', function () {
    Piv::factory()->create(['piv_id' => 100]);

    expect(Piv::find(100))->not->toBeNull();
});

it('belongsTo three operadores', function () {
    Operador::factory()->create(['operador_id' => 1]);
    Operador::factory()->create(['operador_id' => 2]);
    Operador::factory()->create(['operador_id' => 3]);
    $p = Piv::factory()->create([
        'operador_id' => 1, 'operador_id_2' => 2, 'operador_id_3' => 3,
    ]);

    expect($p->operadorPrincipal->operador_id)->toBe(1);
    expect($p->operadorSecundario->operador_id)->toBe(2);
    expect($p->operadorTerciario->operador_id)->toBe(3);
});

it('scopeForOperador finds panels regardless of slot 1/2/3', function () {
    Operador::factory()->create(['operador_id' => 5]);
    Piv::factory()->create(['piv_id' => 1, 'operador_id' => 5]);
    Piv::factory()->create(['piv_id' => 2, 'operador_id_2' => 5]);
    Piv::factory()->create(['piv_id' => 3, 'operador_id_3' => 5]);
    Piv::factory()->create(['piv_id' => 4, 'operador_id' => 99]);

    expect(Piv::forOperador(5)->count())->toBe(3);
});

it('Latin1String cast on direccion roundtrips', function () {
    $p = Piv::factory()->create(['direccion' => 'Avenida de Móstoles, 142']);
    $p->refresh();

    expect($p->direccion)->toBe('Avenida de Móstoles, 142');
});
