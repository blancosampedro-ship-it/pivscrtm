<?php

declare(strict_types=1);

use App\Filament\Pages\PlanificadorDelDia;
use App\Models\LvAveriaIcca;
use App\Models\LvRutaDia;
use App\Models\Modulo;
use App\Models\Piv;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use App\Models\Tecnico;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    $municipio = Modulo::factory()->municipio('Alcalá')->create();
    $ruta = PivRuta::factory()->create(['codigo' => 'ROSA-E', 'sort_order' => 1]);
    PivRutaMunicipio::factory()->create(['ruta_id' => $ruta->id, 'municipio_modulo_id' => $municipio->modulo_id, 'km_desde_ciempozuelos' => 20]);
    $piv = Piv::factory()->create(['municipio' => (string) $municipio->modulo_id]);
    LvAveriaIcca::factory()->create(['piv_id' => $piv->piv_id]);
});

it('header action Crear ruta del día crea ruta y redirige a edit', function (): void {
    $tecnico = Tecnico::factory()->create(['status' => 1]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-06 10:00:00', 'Europe/Madrid'));

    try {
        Livewire::test(PlanificadorDelDia::class)
            ->assertActionVisible('crearRutaDia')
            ->callAction('crearRutaDia', data: [
                'tecnico_id' => $tecnico->tecnico_id,
                'incluir_ambiguas' => true,
            ]);
    } finally {
        CarbonImmutable::setTestNow();
    }

    $ruta = LvRutaDia::query()->firstOrFail();
    expect($ruta->tecnico_id)->toBe($tecnico->tecnico_id);
    expect($ruta->items()->count())->toBe(1);
});

it('header action respeta checkbox excluir ambiguas', function (): void {
    LvAveriaIcca::factory()->create(['piv_id' => null, 'panel_id_sgip' => 'PANEL 17474B']);
    $tecnico = Tecnico::factory()->create(['status' => 1]);

    Livewire::test(PlanificadorDelDia::class)
        ->callAction('crearRutaDia', data: [
            'tecnico_id' => $tecnico->tecnico_id,
            'incluir_ambiguas' => false,
        ]);

    $ruta = LvRutaDia::query()->firstOrFail();
    expect($ruta->items()->count())->toBe(1);
});

it('header action con tecnico inactivo no crea ruta', function (): void {
    $inactive = Tecnico::factory()->create(['status' => 0]);

    Livewire::test(PlanificadorDelDia::class)
        ->callAction('crearRutaDia', data: [
            'tecnico_id' => $inactive->tecnico_id,
            'incluir_ambiguas' => true,
        ]);

    expect(LvRutaDia::query()->count())->toBe(0);
});

it('header action con conflicto unique no crea segunda ruta', function (): void {
    $tecnico = Tecnico::factory()->create(['status' => 1]);
    LvRutaDia::factory()->create(['tecnico_id' => $tecnico->tecnico_id, 'fecha' => CarbonImmutable::now('Europe/Madrid')->toDateString()]);

    Livewire::test(PlanificadorDelDia::class)
        ->callAction('crearRutaDia', data: [
            'tecnico_id' => $tecnico->tecnico_id,
            'incluir_ambiguas' => true,
        ]);

    expect(LvRutaDia::query()->count())->toBe(1);
});
