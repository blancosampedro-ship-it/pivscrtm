<?php

declare(strict_types=1);

use App\Models\Correctivo;
use App\Models\LvCorrectivoImagen;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates and reads', function () {
    $img = LvCorrectivoImagen::factory()->create([
        'correctivo_id' => 1,
        'url' => 'storage/app/public/piv-images/correctivo/test.jpg',
    ]);
    expect(LvCorrectivoImagen::find($img->id))->not->toBeNull();
});

it('relates to a Correctivo via correctivo_id', function () {
    Correctivo::factory()->create(['correctivo_id' => 100]);
    $img = LvCorrectivoImagen::factory()->create(['correctivo_id' => 100]);
    expect($img->correctivo->correctivo_id)->toBe(100);
});

it('Correctivo->imagenes() returns ordered HasMany', function () {
    $c = Correctivo::factory()->create(['correctivo_id' => 200]);
    LvCorrectivoImagen::factory()->create(['correctivo_id' => 200, 'posicion' => 3]);
    LvCorrectivoImagen::factory()->create(['correctivo_id' => 200, 'posicion' => 1]);
    LvCorrectivoImagen::factory()->create(['correctivo_id' => 200, 'posicion' => 2]);
    $imgs = $c->fresh()->imagenes;
    expect($imgs)->toHaveCount(3);
    expect($imgs[0]->posicion)->toBe(1);
    expect($imgs[2]->posicion)->toBe(3);
});
