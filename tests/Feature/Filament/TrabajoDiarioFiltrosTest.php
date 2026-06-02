<?php

declare(strict_types=1);

use App\Filament\Resources\AveriaResource\Pages\ListAverias;
use App\Filament\Resources\TecnicoResource\Pages\ListTecnicos;
use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// ---------- AveriaResource: filtro "estado" ----------

it('averia estado filter oculta cerradas por defecto (pendientes)', function (): void {
    Piv::factory()->create(['piv_id' => 97001]);
    $pendiente = Averia::factory()->create(['averia_id' => 97001, 'piv_id' => 97001, 'status' => 1]);
    $cerrada = Averia::factory()->create(['averia_id' => 97002, 'piv_id' => 97001, 'status' => 2]);

    Livewire::test(ListAverias::class)
        ->assertCanSeeTableRecords([$pendiente])
        ->assertCanNotSeeTableRecords([$cerrada]);
});

it('averia estado filter cerradas y todas', function (): void {
    Piv::factory()->create(['piv_id' => 97010]);
    $pendiente = Averia::factory()->create(['averia_id' => 97010, 'piv_id' => 97010, 'status' => 1]);
    $cerrada = Averia::factory()->create(['averia_id' => 97011, 'piv_id' => 97010, 'status' => 2]);

    Livewire::test(ListAverias::class)
        ->filterTable('estado', 'cerradas')
        ->assertCanSeeTableRecords([$cerrada])
        ->assertCanNotSeeTableRecords([$pendiente]);

    Livewire::test(ListAverias::class)
        ->filterTable('estado', 'todas')
        ->assertCanSeeTableRecords([$pendiente, $cerrada]);
});

// ---------- AveriaResource: filtro "periodo" ----------

it('averia periodo filter por mes y año', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 10:00:00', 'Europe/Madrid'));

    Piv::factory()->create(['piv_id' => 97020]);
    $esteMes = Averia::factory()->create(['averia_id' => 97020, 'piv_id' => 97020, 'status' => 1, 'fecha' => '2026-06-10 09:00:00']);
    $esteAnioOtroMes = Averia::factory()->create(['averia_id' => 97021, 'piv_id' => 97020, 'status' => 1, 'fecha' => '2026-02-10 09:00:00']);
    $anioPasado = Averia::factory()->create(['averia_id' => 97022, 'piv_id' => 97020, 'status' => 1, 'fecha' => '2025-06-10 09:00:00']);

    // 'todo' (default): ve todas
    Livewire::test(ListAverias::class)
        ->assertCanSeeTableRecords([$esteMes, $esteAnioOtroMes, $anioPasado]);

    // 'anio': solo el año en curso
    Livewire::test(ListAverias::class)
        ->filterTable('periodo', 'anio')
        ->assertCanSeeTableRecords([$esteMes, $esteAnioOtroMes])
        ->assertCanNotSeeTableRecords([$anioPasado]);

    // 'mes': solo el mes en curso
    Livewire::test(ListAverias::class)
        ->filterTable('periodo', 'mes')
        ->assertCanSeeTableRecords([$esteMes])
        ->assertCanNotSeeTableRecords([$esteAnioOtroMes, $anioPasado]);
});

// ---------- TecnicoResource: filtro "operatividad" ----------

it('tecnico operatividad filter muestra solo operativos por defecto', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 10:00:00', 'Europe/Madrid'));

    $piv = Piv::factory()->create(['piv_id' => 96000]);
    $operativo = Tecnico::factory()->create(['tecnico_id' => 96001, 'nombre_completo' => 'Tecnico Operativo', 'status' => 1]);
    $inactivo = Tecnico::factory()->create(['tecnico_id' => 96002, 'nombre_completo' => 'Tecnico Sin Actividad', 'status' => 1]);

    $a1 = Averia::factory()->create(['averia_id' => 96001, 'piv_id' => 96000]);
    Asignacion::factory()->create([
        'averia_id' => $a1->averia_id,
        'tecnico_id' => $operativo->tecnico_id,
        'fecha' => '2026-06-05', // dentro de 60 días
    ]);

    $a2 = Averia::factory()->create(['averia_id' => 96002, 'piv_id' => 96000]);
    Asignacion::factory()->create([
        'averia_id' => $a2->averia_id,
        'tecnico_id' => $inactivo->tecnico_id,
        'fecha' => '2026-01-05', // hace >60 días
    ]);

    // default 'operativos': solo el que tiene asignación reciente
    Livewire::test(ListTecnicos::class)
        ->assertCanSeeTableRecords([$operativo])
        ->assertCanNotSeeTableRecords([$inactivo]);

    // 'todos': ambos
    Livewire::test(ListTecnicos::class)
        ->filterTable('operatividad', 'todos')
        ->assertCanSeeTableRecords([$operativo, $inactivo]);

    // 'sin_actividad': solo el sin actividad reciente
    Livewire::test(ListTecnicos::class)
        ->filterTable('operatividad', 'sin_actividad')
        ->assertCanSeeTableRecords([$inactivo])
        ->assertCanNotSeeTableRecords([$operativo]);
});
