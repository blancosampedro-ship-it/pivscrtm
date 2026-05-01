<?php

declare(strict_types=1);

use App\Filament\Resources\PivResource;
use App\Filament\Resources\PivResource\Pages\CreatePiv;
use App\Filament\Resources\PivResource\Pages\ListPivs;
use App\Models\Modulo;
use App\Models\Operador;
use App\Models\Piv;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

// ---------- DoD obligatorios ----------

it('admin_can_list_pivs', function () {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    $operador = Operador::factory()->create();
    $pivs = Piv::factory()->count(3)->create([
        'municipio' => (string) $municipio->modulo_id,
        'operador_id' => $operador->operador_id,
    ]);

    Livewire::test(ListPivs::class)
        ->assertCanSeeTableRecords($pivs);
});

it('non_admin_cannot_access_piv_resource', function () {
    $tecnico = User::factory()->tecnico()->create();
    $this->actingAs($tecnico);

    $this->get(PivResource::getUrl('index'))->assertForbidden();
});

it('piv_listing_no_n_plus_one', function () {
    // 50 paneles, cada uno con municipio + operador principal + industria distintos.
    $pivs = collect(range(1, 50))->map(function ($i) {
        $municipio = Modulo::factory()->municipio()->create();
        $industria = Modulo::factory()->industria()->create();
        $operador = Operador::factory()->create();

        return Piv::factory()->create([
            'piv_id' => 10000 + $i,
            'municipio' => (string) $municipio->modulo_id,
            'industria_id' => $industria->modulo_id,
            'operador_id' => $operador->operador_id,
        ]);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();

    // Solo los primeros 10 son visibles (default pagination). Comprobar que
    // se renderizan implica que se ejecutó el query principal + eager loads.
    Livewire::test(ListPivs::class)->assertCanSeeTableRecords($pivs->take(10));

    $count = count(DB::getQueryLog());
    expect($count)->toBeLessThanOrEqual(8, "Se ejecutaron {$count} queries — eager loading roto");
});

it('municipio_validation_accepts_zero_sentinel', function () {
    Livewire::test(CreatePiv::class)
        ->fillForm([
            'piv_id' => 99001,
            'parada_cod' => 'TEST-001',
            'direccion' => 'Calle Test 1',
            'municipio' => '0',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Piv::find(99001))->not->toBeNull();
    expect(Piv::find(99001)->municipio)->toBe('0');
});

it('municipio_validation_accepts_valid_modulo_id', function () {
    $m = Modulo::factory()->municipio('Madrid')->create();

    Livewire::test(CreatePiv::class)
        ->fillForm([
            'piv_id' => 99002,
            'parada_cod' => 'TEST-002',
            'direccion' => 'Calle Test 2',
            'municipio' => (string) $m->modulo_id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

it('municipio_validation_rejects_invalid_id', function () {
    Livewire::test(CreatePiv::class)
        ->fillForm([
            'piv_id' => 99003,
            'parada_cod' => 'TEST-003',
            'direccion' => 'Calle Test 3',
            'municipio' => '999999',
        ])
        ->call('create')
        ->assertHasFormErrors(['municipio']);

    expect(Piv::find(99003))->toBeNull();
});

it('municipio_validation_rejects_modulo_with_wrong_tipo', function () {
    $industriaModulo = Modulo::factory()->industria()->create();

    Livewire::test(CreatePiv::class)
        ->fillForm([
            'piv_id' => 99004,
            'parada_cod' => 'TEST-004',
            'direccion' => 'Calle Test 4',
            'municipio' => (string) $industriaModulo->modulo_id,
        ])
        ->call('create')
        ->assertHasFormErrors(['municipio']);

    expect(Piv::find(99004))->toBeNull();
});

// ---------- Bloque 07d (SaaS pivot) ----------

it('pivs_list_shows_thumbnail_when_imagenes_present', function () {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    $piv = Piv::factory()->create([
        'piv_id' => 99100,
        'municipio' => (string) $municipio->modulo_id,
    ]);
    DB::table('piv_imagen')->insert([
        'piv_id' => 99100,
        'url' => '99100-test.jpg',
        'posicion' => 1,
    ]);

    $piv->refresh();
    expect($piv->thumbnail_url)->toBe('https://www.winfin.es/images/piv/99100-test.jpg');
});

it('piv_thumbnail_url_returns_null_without_imagenes', function () {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    $piv = Piv::factory()->create([
        'piv_id' => 99101,
        'municipio' => (string) $municipio->modulo_id,
    ]);

    expect($piv->thumbnail_url)->toBeNull();
});

it('pivs_list_view_action_renders_infolist_with_imagenes', function () {
    $municipio = Modulo::factory()->municipio('Móstoles')->create();
    $piv = Piv::factory()->create([
        'piv_id' => 99102,
        'parada_cod' => '06036',
        'direccion' => 'av. Juan Carlos I, 22',
        'municipio' => (string) $municipio->modulo_id,
    ]);

    Livewire::test(ListPivs::class)
        ->callTableAction('view', $piv->piv_id)
        ->assertSuccessful();
});
