<?php

declare(strict_types=1);

use App\Models\Piv;
use App\Models\PivImagen;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('loads and resolves piv relation', function () {
    Piv::factory()->create(['piv_id' => 50]);
    $img = PivImagen::factory()->create(['piv_id' => 50, 'url' => 'photo.jpg']);

    expect($img->piv->piv_id)->toBe(50);
});
