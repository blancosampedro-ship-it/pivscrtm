<?php

declare(strict_types=1);

use App\Filament\Resources\LvAveriaIccaResource\Pages\ListLvAveriaIccas;
use App\Models\LvAveriaIcca;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('por defecto el listado ICCA muestra solo las activas (última importación)', function (): void {
    $activa = LvAveriaIcca::factory()->activa()->create();
    $historico = LvAveriaIcca::factory()->inactiva()->create();

    Livewire::test(ListLvAveriaIccas::class)
        ->assertCanSeeTableRecords([$activa])
        ->assertCanNotSeeTableRecords([$historico]);
});

it('el histórico (inactivas) sigue accesible cambiando el filtro', function (): void {
    $activa = LvAveriaIcca::factory()->activa()->create();
    $historico = LvAveriaIcca::factory()->inactiva()->create();

    Livewire::test(ListLvAveriaIccas::class)
        ->filterTable('activa', false) // Solo inactivas
        ->assertCanSeeTableRecords([$historico])
        ->assertCanNotSeeTableRecords([$activa]);
});

it('expone borrado por fila y en lote', function (): void {
    LvAveriaIcca::factory()->activa()->create();

    Livewire::test(ListLvAveriaIccas::class)
        ->assertTableActionExists('delete')
        ->assertTableBulkActionExists('delete');
});

it('permite borrar una ICCA desde su fila', function (): void {
    $icca = LvAveriaIcca::factory()->activa()->create();

    Livewire::test(ListLvAveriaIccas::class)
        ->callTableAction('delete', $icca);

    $this->assertModelMissing($icca);
});

it('permite borrar varias ICCA en lote (selección múltiple)', function (): void {
    $a = LvAveriaIcca::factory()->activa()->create();
    $b = LvAveriaIcca::factory()->activa()->create();

    Livewire::test(ListLvAveriaIccas::class)
        ->callTableBulkAction('delete', [$a, $b]);

    $this->assertModelMissing($a);
    $this->assertModelMissing($b);
});
