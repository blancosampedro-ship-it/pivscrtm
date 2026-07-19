<?php

declare(strict_types=1);

use App\Filament\Resources\AveriaResource\Pages\CreateAveria;
use App\Filament\Resources\PivResource\Pages\ListPivs;
use App\Models\Modulo;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('los paneles se pueden buscar por municipio', function (): void {
    Modulo::factory()->municipio('Aranjuez')->create(['modulo_id' => 95001]);
    Modulo::factory()->municipio('Getafe')->create(['modulo_id' => 95002]);
    $pivAranjuez = Piv::factory()->create(['piv_id' => 95001, 'municipio' => '95001']);
    $pivGetafe = Piv::factory()->create(['piv_id' => 95002, 'municipio' => '95002']);

    Livewire::test(ListPivs::class)
        ->searchTable('Aranjuez')
        ->assertCanSeeTableRecords([$pivAranjuez])
        ->assertCanNotSeeTableRecords([$pivGetafe]);
});

it('el selector de técnico solo ofrece técnicos activos', function (): void {
    $activo = Tecnico::factory()->create(['tecnico_id' => 95010, 'nombre_completo' => 'Tecnico Activo', 'status' => 1]);
    $inactivo = Tecnico::factory()->create(['tecnico_id' => 95011, 'nombre_completo' => 'Tecnico Inactivo', 'status' => 0]);

    $options = Livewire::test(CreateAveria::class)
        ->instance()
        ->form->getComponent('data.tecnico_id')
        ->getOptions();

    expect(array_keys($options))->toContain($activo->tecnico_id);
    expect(array_keys($options))->not->toContain($inactivo->tecnico_id);
});
