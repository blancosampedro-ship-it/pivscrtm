<?php

declare(strict_types=1);

use App\Filament\Resources\PivZonaResource;
use App\Filament\Resources\PivZonaResource\Pages\CreatePivZona;
use App\Filament\Resources\PivZonaResource\Pages\EditPivZona;
use App\Filament\Resources\PivZonaResource\RelationManagers\MunicipiosRelationManager;
use App\Models\Modulo;
use App\Models\PivZona;
use App\Models\PivZonaMunicipio;
use App\Models\User;
use Database\Seeders\PivZonaSeeder;
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

it('pivzona_table_exists_with_correct_columns', function (): void {
    expect(Schema::hasTable('lv_piv_zona'))->toBeTrue();
    expect(Schema::hasColumns('lv_piv_zona', [
        'id', 'nombre', 'color_hint', 'sort_order', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasTable('lv_piv_zona_municipio'))->toBeTrue();
    expect(Schema::hasColumns('lv_piv_zona_municipio', [
        'id', 'zona_id', 'municipio_modulo_id', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $indexes = collect(DB::select("PRAGMA index_list('lv_piv_zona_municipio')"));
    $uniqueIndex = $indexes->first(fn (object $index): bool => $index->name === 'idx_municipio_unique_zona');

    expect($uniqueIndex)->not->toBeNull();
    expect((int) $uniqueIndex->unique)->toBe(1);
});

it('pivzona_seeder_creates_6_zonas_with_correct_names_and_colors', function (): void {
    Artisan::call('db:seed', ['--class' => PivZonaSeeder::class]);

    expect(PivZona::count())->toBe(6);
    expect(PivZona::orderBy('sort_order')->pluck('nombre')->all())->toBe([
        'Madrid Sur',
        'Madrid Norte',
        'Corredor Henares',
        'Sierra Madrid',
        'Madrid Capital',
        'Otros',
    ]);
    expect(PivZona::pluck('color_hint', 'nombre')->all())->toMatchArray([
        'Madrid Sur' => '#0F62FE',
        'Madrid Norte' => '#33B1FF',
        'Corredor Henares' => '#A56EFF',
        'Sierra Madrid' => '#42BE65',
        'Madrid Capital' => '#1D3F8C',
        'Otros' => '#8D8D8D',
    ]);
});

it('pivzona_seeder_assigns_madrid_sur_municipios', function (): void {
    seedMadridSurMunicipios();

    Artisan::call('db:seed', ['--class' => PivZonaSeeder::class]);

    $zona = PivZona::where('nombre', 'Madrid Sur')->firstOrFail();
    $assigned = $zona->municipios()
        ->with('modulo:modulo_id,nombre')
        ->get()
        ->pluck('modulo.nombre')
        ->sort()
        ->values()
        ->all();

    expect($assigned)->toBe(collect(madridSurMunicipios())->sort()->values()->all());
});

it('pivzona_seeder_is_idempotent', function (): void {
    seedMadridSurMunicipios();

    Artisan::call('db:seed', ['--class' => PivZonaSeeder::class]);
    $zonasCount = PivZona::count();
    $municipiosCount = PivZonaMunicipio::count();

    Artisan::call('db:seed', ['--class' => PivZonaSeeder::class]);

    expect(PivZona::count())->toBe($zonasCount);
    expect(PivZonaMunicipio::count())->toBe($municipiosCount);
});

it('pivzona_seeder_warns_on_missing_municipio', function (): void {
    Artisan::call('db:seed', ['--class' => PivZonaSeeder::class]);

    expect(Artisan::output())->toContain('Municipio NO encontrado en modulo: Móstoles (zona Madrid Sur)');
});

it('admin_can_view_zonas_list_in_filament', function (): void {
    PivZona::factory()->create(['nombre' => 'Madrid Sur', 'color_hint' => '#0F62FE', 'sort_order' => 1]);

    $this->get('/admin/zonas')
        ->assertOk()
        ->assertSee('Madrid Sur');

    expect(PivZonaResource::getNavigationGroup())->toBe('Operaciones');
});

it('admin_can_create_new_zona', function (): void {
    Livewire::test(CreatePivZona::class)
        ->fillForm([
            'nombre' => 'Madrid Oeste',
            'color_hint' => '#FF832B',
            'sort_order' => 6,
        ])
        ->call('create')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lv_piv_zona', [
        'nombre' => 'Madrid Oeste',
        'color_hint' => '#FF832B',
        'sort_order' => 6,
    ]);
});

it('admin_can_edit_zona_color', function (): void {
    $zona = PivZona::factory()->create(['color_hint' => '#0F62FE']);

    Livewire::test(EditPivZona::class, ['record' => $zona->getRouteKey()])
        ->fillForm(['color_hint' => '#42BE65'])
        ->call('save')
        ->assertHasNoErrors();

    expect($zona->refresh()->color_hint)->toBe('#42BE65');
});

it('admin_can_assign_new_municipio_to_zona_via_relation_manager', function (): void {
    $zona = PivZona::factory()->create(['nombre' => 'Madrid Sur']);
    $municipio = Modulo::factory()->municipio('Cubas de la Sagra')->create();

    Livewire::test(MunicipiosRelationManager::class, [
        'ownerRecord' => $zona,
        'pageClass' => EditPivZona::class,
    ])
        ->callTableAction('create', data: [
            'municipio_modulo_id' => $municipio->modulo_id,
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('lv_piv_zona_municipio', [
        'zona_id' => $zona->id,
        'municipio_modulo_id' => $municipio->modulo_id,
    ]);
});

it('pivzona_zona_id_unique_per_municipio', function (): void {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    $zonaA = PivZona::factory()->create(['nombre' => 'Zona A']);
    $zonaB = PivZona::factory()->create(['nombre' => 'Zona B']);

    PivZonaMunicipio::create([
        'zona_id' => $zonaA->id,
        'municipio_modulo_id' => $municipio->modulo_id,
    ]);

    expect(fn () => PivZonaMunicipio::create([
        'zona_id' => $zonaB->id,
        'municipio_modulo_id' => $municipio->modulo_id,
    ]))->toThrow(QueryException::class);
});

function madridSurMunicipios(): array
{
    return [
        'Móstoles', 'Getafe', 'Fuenlabrada', 'Leganés', 'Parla',
        'Alcorcón', 'Pinto', 'Valdemoro', 'Aranjuez', 'Humanes',
        'Arroyomolinos', 'Moraleja de Enmedio', 'Sevilla la Nueva',
        'Navalcarnero', 'Brunete', 'Villaviciosa de Odón',
    ];
}

function seedMadridSurMunicipios(): void
{
    foreach (madridSurMunicipios() as $index => $nombre) {
        Modulo::factory()->municipio($nombre)->create([
            'modulo_id' => 12000 + $index,
        ]);
    }
}
