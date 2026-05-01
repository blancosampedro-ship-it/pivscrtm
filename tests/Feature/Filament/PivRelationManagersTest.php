<?php

declare(strict_types=1);

use App\Filament\Resources\AsignacionResource;
use App\Filament\Resources\AveriaResource;
use App\Filament\Resources\PivResource\Pages\ViewPiv;
use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Modulo;
use App\Models\Piv;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('piv_view_page_renders_with_relation_manager_tabs', function () {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    $piv = Piv::factory()->create(['piv_id' => 88800, 'municipio' => (string) $municipio->modulo_id]);

    Livewire::test(ViewPiv::class, ['record' => $piv->piv_id])
        ->assertSuccessful();
});

it('averias_relation_manager_shows_only_this_pivs_averias', function () {
    $piv1 = Piv::factory()->create(['piv_id' => 88810]);
    $piv2 = Piv::factory()->create(['piv_id' => 88811]);
    Averia::factory()->create(['averia_id' => 88810, 'piv_id' => 88810]);
    Averia::factory()->create(['averia_id' => 88811, 'piv_id' => 88811]);

    // Verifica via la relación Eloquent que el filtrado parent-child es correcto.
    expect($piv1->averias()->pluck('averia_id')->all())->toBe([88810]);
    expect($piv2->averias()->pluck('averia_id')->all())->toBe([88811]);

    // Verifica que la View page renderiza con los RelationManager tabs activos.
    Livewire::test(ViewPiv::class, ['record' => $piv1->piv_id])
        ->assertSuccessful();
});

it('asignaciones_relation_manager_shows_only_this_pivs_asignaciones_via_averias', function () {
    $piv1 = Piv::factory()->create(['piv_id' => 88820]);
    $piv2 = Piv::factory()->create(['piv_id' => 88821]);
    Averia::factory()->create(['averia_id' => 88820, 'piv_id' => 88820]);
    Averia::factory()->create(['averia_id' => 88821, 'piv_id' => 88821]);
    Asignacion::factory()->create(['asignacion_id' => 88820, 'averia_id' => 88820, 'tipo' => 1]);
    Asignacion::factory()->create(['asignacion_id' => 88821, 'averia_id' => 88821, 'tipo' => 2]);

    // HasManyThrough Piv → Asignacion vía Averia.
    expect($piv1->asignaciones()->pluck('asignacion_id')->all())->toBe([88820]);
    expect($piv2->asignaciones()->pluck('asignacion_id')->all())->toBe([88821]);

    // ViewPiv page renderiza con ambos tabs.
    Livewire::test(ViewPiv::class, ['record' => $piv1->piv_id])
        ->assertSuccessful();
});

it('averia_resource_not_in_admin_sidebar_navigation', function () {
    expect(AveriaResource::shouldRegisterNavigation())->toBeFalse();
});

it('asignacion_resource_not_in_admin_sidebar_navigation', function () {
    expect(AsignacionResource::shouldRegisterNavigation())->toBeFalse();
});

it('averia_resource_url_still_accessible_directly', function () {
    // Regression: aunque no esté en sidebar, la URL /admin/averias funciona (Bloque 10 reportes).
    $this->get(AveriaResource::getUrl('index'))->assertOk();
});

it('piv_has_asignaciones_through_averias_relation', function () {
    $piv = Piv::factory()->create(['piv_id' => 88830]);
    Averia::factory()->create(['averia_id' => 88830, 'piv_id' => 88830]);
    Asignacion::factory()->create(['asignacion_id' => 88830, 'averia_id' => 88830]);

    expect($piv->asignaciones()->count())->toBe(1);
});
