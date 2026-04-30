<?php

declare(strict_types=1);

use App\Models\Tecnico;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses tecnico_id as primary key', function () {
    Tecnico::factory()->create(['tecnico_id' => 5]);

    expect(Tecnico::find(5))->not->toBeNull();
});

it('hides clave from serialization (ADR-0008 + regla #3 RGPD)', function () {
    $t = Tecnico::factory()->create(['clave' => 'should-not-leak']);

    expect($t->toArray())->not->toHaveKey('clave');
    expect($t->toJson())->not->toContain('should-not-leak');
});

it('Latin1String cast applied on nombre_completo and direccion', function () {
    $t = Tecnico::factory()->create([
        'nombre_completo' => 'Rubén Martín',
        'direccion' => 'Calle Mayor 1, Móstoles',
    ]);
    $t->refresh();

    expect($t->nombre_completo)->toBe('Rubén Martín');
    expect($t->direccion)->toBe('Calle Mayor 1, Móstoles');
});
