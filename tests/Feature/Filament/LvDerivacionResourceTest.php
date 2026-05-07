<?php

declare(strict_types=1);

use App\Filament\Resources\LvDerivacionResource;
use App\Filament\Resources\LvDerivacionResource\Pages\ListLvDerivaciones;
use App\Filament\Resources\LvRutaDiaResource\Pages\EditLvRutaDia;
use App\Filament\Resources\LvRutaDiaResource\RelationManagers\ItemsRelationManager;
use App\Models\LvDerivacion;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('admin puede acceder al resource derivaciones y slug es explicito', function (): void {
    $derivacion = LvDerivacion::factory()->create();

    $this->get(LvDerivacionResource::getUrl('index'))
        ->assertOk()
        ->assertSee(LvDerivacionResource::causaLabel($derivacion->tipo_causa));

    expect(LvDerivacionResource::getSlug())->toBe('derivaciones');
    expect(LvDerivacionResource::getNavigationGroup())->toBe('Planificación');
    expect(LvDerivacionResource::getNavigationLabel())->toBe('Derivaciones');
});

it('header action Nueva derivacion crea derivacion', function (): void {
    $item = LvRutaDiaItem::factory()->create();

    Livewire::test(ListLvDerivaciones::class)
        ->callTableAction('nuevaDerivacion', data: [
            'lv_ruta_dia_item_id' => $item->id,
            'tipo_causa' => LvDerivacion::CAUSA_TERCERO,
            'actor_responsable' => LvDerivacion::ACTOR_INTERNO_WINFIN,
            'notas_derivacion' => 'Pendiente de tercero',
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('lv_derivacion', [
        'lv_ruta_dia_item_id' => $item->id,
        'status' => LvDerivacion::STATUS_PENDIENTE_TERCERO,
    ]);
});

it('acciones por fila cambian status sin crash', function (): void {
    $derivacion = LvDerivacion::factory()->create();

    Livewire::test(ListLvDerivaciones::class)
        ->assertTableActionExists('resolverExternamente')
        ->assertTableActionExists('devolverARuta')
        ->assertTableActionExists('cancelar')
        ->assertTableActionExists('view')
        ->callTableAction('resolverExternamente', $derivacion, ['notas' => 'Hecho'])
        ->assertHasNoTableActionErrors();

    expect($derivacion->fresh()->status)->toBe(LvDerivacion::STATUS_RESUELTO_EXTERNO);
});

it('relation manager permite derivar item desde ruta', function (): void {
    $ruta = LvRutaDia::factory()->create();
    $item = LvRutaDiaItem::factory()->create(['ruta_dia_id' => $ruta->id]);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $ruta,
        'pageClass' => EditLvRutaDia::class,
    ])->callTableAction('derivar', $item, [
        'tipo_causa' => LvDerivacion::CAUSA_MATERIAL,
        'actor_responsable' => LvDerivacion::ACTOR_PROVEEDOR,
        'actor_notas' => 'Proveedor local',
    ])->assertHasNoTableActionErrors();

    expect($item->fresh()->status)->toBe(LvRutaDiaItem::STATUS_DERIVADO);
    expect($item->fresh()->tieneDerivacionAbierta())->toBeTrue();
});

it('resource list con 100 derivaciones renderiza sin n mas uno evidente', function (): void {
    LvDerivacion::factory()->count(100)->create();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    Livewire::test(ListLvDerivaciones::class)
        ->assertOk();

    expect(count($queries))->toBeLessThan(45);
});

it('navigation badge cuenta derivaciones abiertas', function (): void {
    LvDerivacion::factory()->create();
    LvDerivacion::factory()->enCurso()->create();
    LvDerivacion::factory()->cerrada()->create();

    expect(LvDerivacionResource::getNavigationBadge())->toBe('2');
});
