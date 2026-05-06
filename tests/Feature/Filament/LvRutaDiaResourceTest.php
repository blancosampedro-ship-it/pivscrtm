<?php

declare(strict_types=1);

use App\Filament\Resources\LvRutaDiaResource;
use App\Filament\Resources\LvRutaDiaResource\Pages\EditLvRutaDia;
use App\Filament\Resources\LvRutaDiaResource\Pages\ListLvRutaDias;
use App\Filament\Resources\LvRutaDiaResource\RelationManagers\ItemsRelationManager;
use App\Models\LvAveriaIcca;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
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
});

it('admin puede acceder al resource rutas-dia y slug es explicito', function (): void {
    $ruta = LvRutaDia::factory()->create();

    $this->get(LvRutaDiaResource::getUrl('index'))
        ->assertOk()
        ->assertSee($ruta->fecha->format('Y-m-d'));

    expect(LvRutaDiaResource::getSlug())->toBe('rutas-dia');
    expect(LvRutaDiaResource::getNavigationGroup())->toBe('Planificación');
    expect(LvRutaDiaResource::getNavigationLabel())->toBe('Rutas del día');
});

it('non admin no puede acceder a rutas-dia', function (): void {
    $this->actingAs(User::factory()->tecnico()->create());

    $this->get(LvRutaDiaResource::getUrl('index'))->assertForbidden();
});

it('listado filtra por tecnico y status', function (): void {
    $tecnicoA = Tecnico::factory()->create(['nombre_completo' => 'Técnico A']);
    $tecnicoB = Tecnico::factory()->create(['nombre_completo' => 'Técnico B']);
    $rutaA = LvRutaDia::factory()->create(['tecnico_id' => $tecnicoA->tecnico_id, 'status' => LvRutaDia::STATUS_PLANIFICADA]);
    $rutaB = LvRutaDia::factory()->create(['tecnico_id' => $tecnicoB->tecnico_id, 'status' => LvRutaDia::STATUS_CANCELADA]);

    Livewire::test(ListLvRutaDias::class)
        ->filterTable('tecnico_id', $tecnicoA->tecnico_id)
        ->assertCanSeeTableRecords([$rutaA])
        ->assertCanNotSeeTableRecords([$rutaB]);

    Livewire::test(ListLvRutaDias::class)
        ->filterTable('status', LvRutaDia::STATUS_CANCELADA)
        ->assertCanSeeTableRecords([$rutaB])
        ->assertCanNotSeeTableRecords([$rutaA]);
});

it('edit page muestra items de la ruta', function (): void {
    $ruta = LvRutaDia::factory()->create();
    $item = LvRutaDiaItem::factory()->create(['ruta_dia_id' => $ruta->id, 'orden' => 1]);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $ruta,
        'pageClass' => EditLvRutaDia::class,
    ])->assertCanSeeTableRecords([$item]);
});

it('ruta completada no acepta cambios de cabecera desde edit form', function (): void {
    $ruta = LvRutaDia::factory()->completada()->create(['notas_admin' => 'original']);

    Livewire::test(EditLvRutaDia::class, ['record' => $ruta->getRouteKey()])
        ->fillForm(['notas_admin' => 'cambio no permitido'])
        ->call('save')
        ->assertHasNoErrors();

    expect($ruta->fresh()->notas_admin)->toBe('original');
});

it('bulk action cancelar cambia status', function (): void {
    $rutas = LvRutaDia::factory()->count(2)->sequence(
        ['fecha' => '2026-05-06'],
        ['fecha' => '2026-05-07'],
    )->create();

    Livewire::test(ListLvRutaDias::class)
        ->callTableBulkAction('cancelar', $rutas)
        ->assertHasNoTableBulkActionErrors();

    $rutas->each(fn (LvRutaDia $ruta) => expect($ruta->fresh()->status)->toBe(LvRutaDia::STATUS_CANCELADA));
});

it('relation manager permite eliminar item sin tocar averia origen', function (): void {
    $ruta = LvRutaDia::factory()->create();
    $averia = LvAveriaIcca::factory()->create();
    $item = LvRutaDiaItem::factory()->create(['ruta_dia_id' => $ruta->id, 'lv_averia_icca_id' => $averia->id]);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $ruta,
        'pageClass' => EditLvRutaDia::class,
    ])->callTableAction('delete', $item)
        ->assertHasNoTableActionErrors();

    expect(LvRutaDiaItem::query()->whereKey($item->id)->exists())->toBeFalse();
    expect(LvAveriaIcca::query()->whereKey($averia->id)->exists())->toBeTrue();
});

it('relation manager permite añadir item desde propuesta del planificador', function (): void {
    $municipio = Modulo::factory()->municipio('Alcalá')->create();
    $rutaOperativa = PivRuta::factory()->create(['codigo' => 'ROSA-E', 'sort_order' => 1]);
    PivRutaMunicipio::factory()->create(['ruta_id' => $rutaOperativa->id, 'municipio_modulo_id' => $municipio->modulo_id, 'km_desde_ciempozuelos' => 20]);
    $piv = Piv::factory()->create(['municipio' => (string) $municipio->modulo_id]);
    $averia = LvAveriaIcca::factory()->create(['piv_id' => $piv->piv_id]);
    $rutaDia = LvRutaDia::factory()->create(['fecha' => '2026-05-06']);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $rutaDia,
        'pageClass' => EditLvRutaDia::class,
    ])->callTableAction('anadirDesdePropuesta', data: [
        'item_key' => 'correctivo:'.$averia->id,
    ])->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('lv_ruta_dia_item', [
        'ruta_dia_id' => $rutaDia->id,
        'tipo_item' => LvRutaDiaItem::TIPO_CORRECTIVO,
        'lv_averia_icca_id' => $averia->id,
        'orden' => 1,
    ]);
});

it('navigation badge cuenta rutas abiertas de hoy', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-06 10:00:00', 'Europe/Madrid'));

    try {
        LvRutaDia::factory()->create(['fecha' => '2026-05-06', 'status' => LvRutaDia::STATUS_PLANIFICADA]);
        LvRutaDia::factory()->create(['fecha' => '2026-05-06', 'status' => LvRutaDia::STATUS_EN_PROGRESO]);
        LvRutaDia::factory()->create(['fecha' => '2026-05-06', 'status' => LvRutaDia::STATUS_COMPLETADA]);
        LvRutaDia::factory()->create(['fecha' => '2026-05-07', 'status' => LvRutaDia::STATUS_PLANIFICADA]);

        expect(LvRutaDiaResource::getNavigationBadge())->toBe('2');
    } finally {
        CarbonImmutable::setTestNow();
    }
});
