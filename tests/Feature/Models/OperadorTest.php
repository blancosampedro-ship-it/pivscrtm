<?php

declare(strict_types=1);

use App\Models\Operador;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses operador_id as primary key', function () {
    Operador::factory()->create(['operador_id' => 7]);

    expect(Operador::find(7))->not->toBeNull();
});

it('hides clave from serialization', function () {
    $o = Operador::factory()->create(['clave' => 'should-not-leak']);

    expect($o->toArray())->not->toHaveKey('clave');
    expect($o->toJson())->not->toContain('should-not-leak');
});

it('Latin1String cast on razon_social and domicilio', function () {
    $o = Operador::factory()->create([
        'razon_social' => 'EMT Móstoles S.A.',
        'domicilio' => 'Calle del Sol, Cádiz',
    ]);
    $o->refresh();

    expect($o->razon_social)->toBe('EMT Móstoles S.A.');
    expect($o->domicilio)->toBe('Calle del Sol, Cádiz');
});
