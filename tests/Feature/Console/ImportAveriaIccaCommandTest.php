<?php

declare(strict_types=1);

use App\Models\LvAveriaIcca;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lv import averia icca command imports a valid file after confirmation', function (): void {
    $admin = User::factory()->admin()->create();
    $path = tempnam(sys_get_temp_dir(), 'sgip_');
    file_put_contents($path, "Id,Categoría,Resumen,Estado,Descripción,NOTAS,Asignada a\n0028078,Panel apagado,PANEL 07022,asignada,Apagado,,SGIP_winfin\n");

    $this->artisan('lv:import-averia-icca', ['file' => $path, '--user' => $admin->id])
        ->expectsConfirmation('Aplicar import (mark inactive 0)?', 'yes')
        ->assertSuccessful();

    expect(LvAveriaIcca::where('sgip_id', '0028078')->exists())->toBeTrue();
});

it('lv import averia icca command rejects missing file', function (): void {
    User::factory()->admin()->create(['id' => 1]);

    $this->artisan('lv:import-averia-icca', ['file' => '/tmp/no-existe-sgip.csv'])
        ->assertFailed();
});
