<?php

declare(strict_types=1);

use App\Enums\AveriaStatus;
use App\Filament\Resources\AveriaResource\Pages\ListAverias;
use App\Models\Averia;
use App\Models\Piv;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

// --- Enum: diccionario código -> etiqueta (capa de presentación) ---

it('mapea los códigos de estado a etiquetas legibles', function (): void {
    expect(AveriaStatus::labelFor(1))->toBe('Abierta');
    expect(AveriaStatus::labelFor(2))->toBe('Resuelta');
    expect(AveriaStatus::labelFor(3))->toBe('En curso');
    expect(AveriaStatus::labelFor(4))->toBe('Bloqueada / Otro');
    expect(AveriaStatus::labelFor(5))->toBe('Retirada / No procede');
});

it('tolera códigos fuera de catálogo y valores vacíos sin romper', function (): void {
    expect(AveriaStatus::labelFor(99))->toBe('Estado 99');
    expect(AveriaStatus::labelFor(null))->toBe('—');
    expect(AveriaStatus::labelFor('—'))->toBe('—');
    expect(AveriaStatus::colorFor(99))->toBe('gray');
    expect(AveriaStatus::colorFor(null))->toBe('gray');
});

it('considera pendientes 1, 3 y 4 y excluye 2 y 5', function (): void {
    expect(AveriaStatus::pendientes())->toBe([1, 3, 4]);
    expect(AveriaStatus::from(1)->isPendiente())->toBeTrue();
    expect(AveriaStatus::from(3)->isPendiente())->toBeTrue();
    expect(AveriaStatus::from(4)->isPendiente())->toBeTrue();
    expect(AveriaStatus::from(2)->isPendiente())->toBeFalse();
    expect(AveriaStatus::from(5)->isPendiente())->toBeFalse();
});

// --- Filtro "Pendientes" en el listado ---

it('el filtro Pendientes (por defecto) incluye status 1 y 4 y excluye 2', function (): void {
    Piv::factory()->create(['piv_id' => 70001]);
    $abierta = Averia::factory()->create(['averia_id' => 70001, 'piv_id' => 70001, 'status' => 1]);
    $resuelta = Averia::factory()->create(['averia_id' => 70002, 'piv_id' => 70001, 'status' => 2]);
    $bloqueada = Averia::factory()->create(['averia_id' => 70004, 'piv_id' => 70001, 'status' => 4]);

    Livewire::test(ListAverias::class) // filtro estado = 'pendientes' por defecto
        ->assertCanSeeTableRecords([$abierta, $bloqueada])
        ->assertCanNotSeeTableRecords([$resuelta]);
});

it('el filtro Cerradas muestra solo status 2', function (): void {
    Piv::factory()->create(['piv_id' => 70010]);
    $abierta = Averia::factory()->create(['averia_id' => 70011, 'piv_id' => 70010, 'status' => 1]);
    $resuelta = Averia::factory()->create(['averia_id' => 70012, 'piv_id' => 70010, 'status' => 2]);

    Livewire::test(ListAverias::class)
        ->filterTable('estado', 'cerradas')
        ->assertCanSeeTableRecords([$resuelta])
        ->assertCanNotSeeTableRecords([$abierta]);
});

it('la tabla muestra etiquetas legibles, no el código numérico', function (): void {
    Piv::factory()->create(['piv_id' => 70020]);
    Averia::factory()->create(['averia_id' => 70021, 'piv_id' => 70020, 'status' => 1]);
    Averia::factory()->create(['averia_id' => 70022, 'piv_id' => 70020, 'status' => 2]);
    Averia::factory()->create(['averia_id' => 70024, 'piv_id' => 70020, 'status' => 4]);

    Livewire::test(ListAverias::class)
        ->filterTable('estado', 'todas')
        ->assertSee('Abierta')
        ->assertSee('Resuelta')
        ->assertSee('Bloqueada / Otro');
});

// --- Garantía: es solo presentación, no escribe ni migra ---

it('ver el listado no modifica el status de las averías (capa de presentación)', function (): void {
    Piv::factory()->create(['piv_id' => 70030]);
    Averia::factory()->create(['averia_id' => 70034, 'piv_id' => 70030, 'status' => 4]);

    Livewire::test(ListAverias::class)
        ->filterTable('estado', 'todas')
        ->assertSuccessful();

    expect((int) Averia::find(70034)->status)->toBe(4); // intacto
});

it('el conteo de migraciones coincide con el esperado', function (): void {
    // Guard contra migraciones accidentales: actualizar SOLO cuando una PR añada
    // una migración a propósito. 15 base + 1 M1 (cierre local ICCA) + 1 M2 (lv_activity_log).
    $migraciones = glob(database_path('migrations/*.php'));
    expect(count($migraciones))->toBe(17);
});
