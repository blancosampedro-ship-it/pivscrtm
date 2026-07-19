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

it('averia_view_action_renders_when_asignacion_is_null', function () {
    Piv::factory()->create(['piv_id' => 99500]);
    $averia = Averia::factory()->create([
        'averia_id' => 99500,
        'piv_id' => 99500,
        'tecnico_id' => null,
    ]);
    // Importante: NO creamos asignación.

    Livewire::test(ListAverias::class)
        ->callTableAction('view', $averia->averia_id)
        ->assertSuccessful();
});

it('averia list expone las acciones Ver y Editar por fila', function () {
    Piv::factory()->create(['piv_id' => 96900]);
    Averia::factory()->create(['averia_id' => 96900, 'piv_id' => 96900, 'status' => 1]);

    Livewire::test(ListAverias::class)
        ->assertTableActionExists('view')
        ->assertTableActionExists('edit');
});

it('averia_row_click_abre_ver_y_acciones_en_kebab', function () {
    Piv::factory()->create(['piv_id' => 96901]);
    $averia = Averia::factory()->create(['averia_id' => 96901, 'piv_id' => 96901, 'status' => 1]);

    // La fila entera es clicable y abre la acción 'view' (slide-over).
    $table = Livewire::test(ListAverias::class)->instance()->getTable();
    expect($table->getRecordAction($averia))->toBe('view');

    // Las acciones van en un kebab (ActionGroup), mismo patrón que PivResource.
    $source = file_get_contents(app_path('Filament/Resources/AveriaResource.php'));
    expect($source)->toContain('Tables\Actions\ActionGroup::make([');
});

it('averia_view_action_montable_desde_el_kebab', function () {
    Piv::factory()->create(['piv_id' => 96902]);
    $averia = Averia::factory()->create(['averia_id' => 96902, 'piv_id' => 96902, 'status' => 1]);

    Livewire::test(ListAverias::class)
        ->mountTableAction('view', $averia->averia_id)
        ->assertSuccessful();
});
