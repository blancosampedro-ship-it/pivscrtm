<?php

declare(strict_types=1);

use App\Filament\Resources\AveriaResource;
use App\Filament\Resources\AveriaResource\Pages\ListAverias;
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

it('admin_can_list_averias', function () {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    Piv::factory()->create(['piv_id' => 99100, 'municipio' => (string) $municipio->modulo_id]);
    $averia = Averia::factory()->create(['averia_id' => 99200, 'piv_id' => 99100]);

    Livewire::test(ListAverias::class)
        ->assertCanSeeTableRecords([$averia]);
});

it('non_admin_cannot_access_averia_resource', function () {
    $tecnico = User::factory()->tecnico()->create();
    $this->actingAs($tecnico);

    $this->get(AveriaResource::getUrl('index'))->assertForbidden();
});

it('averia_listing_no_n_plus_one', function () {
    $municipio = Modulo::factory()->municipio()->create();
    $operador = Operador::factory()->create();
    $tecnico = Tecnico::factory()->create();

    collect(range(1, 50))->each(function ($i) use ($municipio, $operador, $tecnico) {
        $pivId = 50000 + $i;
        Piv::factory()->create([
            'piv_id' => $pivId,
            'municipio' => (string) $municipio->modulo_id,
            'operador_id' => $operador->operador_id,
        ]);
        Averia::factory()->create([
            'averia_id' => 50000 + $i,
            'piv_id' => $pivId,
            'operador_id' => $operador->operador_id,
            'tecnico_id' => $tecnico->tecnico_id,
        ]);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListAverias::class)->assertSuccessful();
    $count = count(DB::getQueryLog());
    expect($count)->toBeLessThanOrEqual(12, "Eager loading roto: {$count} queries");
});
