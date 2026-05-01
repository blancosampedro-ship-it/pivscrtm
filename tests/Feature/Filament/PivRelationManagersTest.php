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

it('piv_view_page_renders_with_averias_partial', function () {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    $piv = Piv::factory()->create(['piv_id' => 88800, 'municipio' => (string) $municipio->modulo_id]);

    Livewire::test(ViewPiv::class, ['record' => $piv->piv_id])
        ->assertSuccessful()
        ->assertSee('Histórico de averías');
});

it('averias_partial_shows_only_this_pivs_averias', function () {
    $piv1 = Piv::factory()->create(['piv_id' => 88810]);
    $piv2 = Piv::factory()->create(['piv_id' => 88811]);
    Averia::factory()->create(['averia_id' => 88810, 'piv_id' => 88810]);
    Averia::factory()->create(['averia_id' => 88811, 'piv_id' => 88811]);

    // La relación Eloquent filtra por piv_id (defensa en profundidad).
    expect($piv1->averias()->pluck('averia_id')->all())->toBe([88810]);
    expect($piv2->averias()->pluck('averia_id')->all())->toBe([88811]);

    // El partial server-rendered muestra solo las del panel actual.
    Livewire::test(ViewPiv::class, ['record' => $piv1->piv_id])
        ->assertSuccessful()
        ->assertSee('#88810')
        ->assertDontSee('#88811');
});

it('asignacion_resource_not_in_admin_sidebar_navigation', function () {
    expect(AsignacionResource::shouldRegisterNavigation())->toBeFalse();
});

it('averia_resource_not_in_admin_sidebar_navigation', function () {
    expect(AveriaResource::shouldRegisterNavigation())->toBeFalse();
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
