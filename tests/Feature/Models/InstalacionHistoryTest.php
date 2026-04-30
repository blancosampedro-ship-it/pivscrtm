<?php

declare(strict_types=1);

use App\Models\DesinstaladoPiv;
use App\Models\Piv;
use App\Models\ReinstaladoPiv;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('desinstalado_piv resolves piv relation and casts observaciones', function () {
    Piv::factory()->create(['piv_id' => 60]);
    $d = DesinstaladoPiv::factory()->create([
        'piv_id' => 60,
        'observaciones' => 'Retirada por reforma de marquesina, Cádiz',
    ]);
    $d->refresh();

    expect($d->piv->piv_id)->toBe(60);
    expect($d->observaciones)->toBe('Retirada por reforma de marquesina, Cádiz');
});

it('reinstalado_piv resolves piv relation and casts observaciones', function () {
    Piv::factory()->create(['piv_id' => 61]);
    $r = ReinstaladoPiv::factory()->create([
        'piv_id' => 61,
        'observaciones' => 'Reinstalado tras obra en Móstoles',
    ]);
    $r->refresh();

    expect($r->piv->piv_id)->toBe(61);
    expect($r->observaciones)->toBe('Reinstalado tras obra en Móstoles');
});
