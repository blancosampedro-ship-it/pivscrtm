<?php

declare(strict_types=1);

use App\Filament\Resources\PivRutaResource;
use App\Filament\Resources\PivRutaResource\Pages\CreatePivRuta;
use App\Filament\Resources\PivRutaResource\Pages\EditPivRuta;
use App\Filament\Resources\PivRutaResource\RelationManagers\MunicipiosRelationManager;
use App\Models\Modulo;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use App\Models\User;
use Database\Seeders\PivRutaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('pivruta tables exist with correct columns', function (): void {
    expect(Schema::hasTable('lv_piv_zona'))->toBeFalse();
    expect(Schema::hasTable('lv_piv_zona_municipio'))->toBeFalse();

    expect(Schema::hasTable('lv_piv_ruta'))->toBeTrue();
    expect(Schema::hasColumns('lv_piv_ruta', [
        'id', 'codigo', 'nombre', 'zona_geografica', 'color_hint', 'km_medio', 'sort_order', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasTable('lv_piv_ruta_municipio'))->toBeTrue();
    expect(Schema::hasColumns('lv_piv_ruta_municipio', [
        'id', 'ruta_id', 'municipio_modulo_id', 'km_desde_ciempozuelos', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $indexes = collect(DB::select("PRAGMA index_list('lv_piv_ruta_municipio')"));
    $uniqueIndex = $indexes->first(fn (object $index): bool => $index->name === 'idx_municipio_unique_ruta');

    expect($uniqueIndex)->not->toBeNull();
    expect((int) $uniqueIndex->unique)->toBe(1);
});

it('admin can view rutas list in filament', function (): void {
    PivRuta::factory()->create([
        'codigo' => PivRuta::COD_ROSA_E,
        'nombre' => 'Rosa Este',
        'color_hint' => '#D02670',
        'sort_order' => 2,
    ]);

    $this->get(PivRutaResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Rosa Este');

    expect(PivRutaResource::getSlug())->toBe('rutas-operativas');
    expect(PivRutaResource::getNavigationLabel())->toBe('Rutas');
    expect(PivRutaResource::getNavigationGroup())->toBe('Operaciones');
});

it('non admin cannot view rutas resource', function (): void {
    $this->actingAs(User::factory()->tecnico()->create());

    $this->get(PivRutaResource::getUrl('index'))->assertForbidden();
});

it('admin can create new ruta', function (): void {
    Livewire::test(CreatePivRuta::class)
        ->fillForm([
            'codigo' => 'TEST',
            'nombre' => 'Ruta Test',
            'zona_geografica' => 'Zona de prueba',
            'color_hint' => '#FF832B',
            'km_medio' => 42,
            'sort_order' => 6,
        ])
        ->call('create')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lv_piv_ruta', [
        'codigo' => 'TEST',
        'nombre' => 'Ruta Test',
        'km_medio' => 42,
    ]);
});

it('admin can edit ruta color and km', function (): void {
    $ruta = PivRuta::factory()->create(['color_hint' => '#0F62FE', 'km_medio' => 80]);

    Livewire::test(EditPivRuta::class, ['record' => $ruta->getRouteKey()])
        ->fillForm(['color_hint' => '#42BE65', 'km_medio' => 85])
        ->call('save')
        ->assertHasNoErrors();

    expect($ruta->refresh()->color_hint)->toBe('#42BE65');
    expect($ruta->km_medio)->toBe(85);
});

it('admin can assign new municipio to ruta via relation manager', function (): void {
    $ruta = PivRuta::factory()->create(['nombre' => 'Rosa Este']);
    $municipio = Modulo::factory()->municipio('Campo Real')->create();

    Livewire::test(MunicipiosRelationManager::class, [
        'ownerRecord' => $ruta,
        'pageClass' => EditPivRuta::class,
    ])
        ->callTableAction('create', data: [
            'municipio_modulo_id' => $municipio->modulo_id,
            'km_desde_ciempozuelos' => 40,
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('lv_piv_ruta_municipio', [
        'ruta_id' => $ruta->id,
        'municipio_modulo_id' => $municipio->modulo_id,
        'km_desde_ciempozuelos' => 40,
    ]);

    expect($ruta->refresh()->municipios()->count())->toBe(1);
});

it('pivruta seeder creates five official rutas in sort order', function (): void {
    Artisan::call('db:seed', ['--class' => PivRutaSeeder::class]);

    expect(PivRuta::count())->toBe(5);
    expect(PivRuta::orderBy('sort_order')->pluck('codigo')->all())->toBe([
        PivRuta::COD_ROSA_NO,
        PivRuta::COD_ROSA_E,
        PivRuta::COD_VERDE,
        PivRuta::COD_AZUL,
        PivRuta::COD_AMARILLO,
    ]);
});

it('pivruta relation returns assigned municipios', function (): void {
    $ruta = PivRuta::factory()->create();
    PivRutaMunicipio::factory()->count(3)->create(['ruta_id' => $ruta->id]);

    expect($ruta->municipios)->toHaveCount(3);
    expect($ruta->municipios->first())->toBeInstanceOf(PivRutaMunicipio::class);
});

it('pivruta municipio_modulo_id unique prevents a municipio in two rutas', function (): void {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    $rutaA = PivRuta::factory()->create(['nombre' => 'Ruta A']);
    $rutaB = PivRuta::factory()->create(['nombre' => 'Ruta B']);

    PivRutaMunicipio::create([
        'ruta_id' => $rutaA->id,
        'municipio_modulo_id' => $municipio->modulo_id,
    ]);

    expect(fn () => PivRutaMunicipio::create([
        'ruta_id' => $rutaB->id,
        'municipio_modulo_id' => $municipio->modulo_id,
    ]))->toThrow(QueryException::class);
});
