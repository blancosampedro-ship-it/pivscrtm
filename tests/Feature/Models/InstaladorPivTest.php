<?php

declare(strict_types=1);

use App\Models\InstaladorPiv;
use App\Models\Piv;
use App\Models\U1;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves piv and instalador relations', function () {
    Piv::factory()->create(['piv_id' => 50]);
    U1::factory()->create(['user_id' => 7, 'username' => 'sergio']);
    $row = InstaladorPiv::factory()->create([
        'piv_id' => 50,
        'instalador_id' => 7,
    ]);

    expect($row->piv->piv_id)->toBe(50);
    expect($row->instalador->user_id)->toBe(7);
    expect($row->instalador->username)->toBe('sergio');
});
