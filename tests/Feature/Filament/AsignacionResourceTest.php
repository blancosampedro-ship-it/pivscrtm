<?php

declare(strict_types=1);

use App\Filament\Resources\AsignacionResource;
use App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones;
use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Modulo;
use App\Models\Operador;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('admin_can_list_asignaciones', function () {
    Piv::factory()->create(['piv_id' => 99300]);
    Averia::factory()->create(['averia_id' => 99300, 'piv_id' => 99300]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 99300,
        'averia_id' => 99300,
        'tipo' => 1,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->assertCanSeeTableRecords([$asig]);
});

it('non_admin_cannot_access_asignacion_resource', function () {
    $tecnico = User::factory()->tecnico()->create();
    $this->actingAs($tecnico);

    $this->get(AsignacionResource::getUrl('index'))->assertForbidden();
});

it('asignacion_listing_no_n_plus_one', function () {
    $municipio = Modulo::factory()->municipio()->create();
    $operador = Operador::factory()->create();
    $tecnico = Tecnico::factory()->create();

    collect(range(1, 50))->each(function ($i) use ($municipio, $operador, $tecnico) {
        $pivId = 60000 + $i;
        Piv::factory()->create([
            'piv_id' => $pivId,
            'municipio' => (string) $municipio->modulo_id,
        ]);
        Averia::factory()->create([
            'averia_id' => 60000 + $i,
            'piv_id' => $pivId,
            'operador_id' => $operador->operador_id,
        ]);
        Asignacion::factory()->create([
            'asignacion_id' => 60000 + $i,
            'averia_id' => 60000 + $i,
            'tecnico_id' => $tecnico->tecnico_id,
            'tipo' => $i % 2 + 1,
        ]);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListAsignaciones::class)->assertSuccessful();
    $count = count(DB::getQueryLog());
    expect($count)->toBeLessThanOrEqual(15, "Eager loading roto: {$count} queries");
});

it('asignacion_tipo_filter_separates_correctivo_from_revision', function () {
    Piv::factory()->create(['piv_id' => 99400]);
    Averia::factory()->create(['averia_id' => 99400, 'piv_id' => 99400]);
    Averia::factory()->create(['averia_id' => 99401, 'piv_id' => 99400]);
    $correctivo = Asignacion::factory()->create([
        'asignacion_id' => 99401,
        'averia_id' => 99400,
        'tipo' => 1,
    ]);
    $revision = Asignacion::factory()->create([
        'asignacion_id' => 99402,
        'averia_id' => 99401,
        'tipo' => 2,
    ]);

    Livewire::test(ListAsignaciones::class)
        ->filterTable('tipo', 1)
        ->assertCanSeeTableRecords([$correctivo])
        ->assertCanNotSeeTableRecords([$revision]);
});
