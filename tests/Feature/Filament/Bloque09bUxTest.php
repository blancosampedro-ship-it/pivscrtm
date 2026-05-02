<?php

declare(strict_types=1);

use App\Filament\Resources\AsignacionResource;
use App\Filament\Resources\AveriaResource;
use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Piv;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('piv_resource_uses_action_group_for_row_actions', function () {
    $source = file_get_contents(app_path('Filament/Resources/PivResource.php'));

    expect($source)->toContain('Tables\\Actions\\ActionGroup::make([');
    expect($source)->toContain("->icon('heroicon-m-ellipsis-vertical')");
});

it('piv_view_action_uses_slideover', function () {
    $source = file_get_contents(app_path('Filament/Resources/PivResource.php'));

    expect($source)->toMatch('/ViewAction::make\(\)[\s\S]*?->slideOver\(\)/');
});

it('piv_view_full_action_navigates_to_view_page', function () {
    $source = file_get_contents(app_path('Filament/Resources/PivResource.php'));

    expect($source)->toContain("Action::make('viewFull')");
    expect($source)->toContain("static::getUrl('view'");
});

it('view_piv_page_has_volver_header_action', function () {
    $source = file_get_contents(app_path('Filament/Resources/PivResource/Pages/ViewPiv.php'));

    expect($source)->toContain("Action::make('back')");
    expect($source)->toContain('Volver al listado');
});

it('asignacion_resource_shows_in_sidebar', function () {
    expect(AsignacionResource::shouldRegisterNavigation())->toBeTrue();
});

it('asignacion_resource_navigation_group_is_operaciones', function () {
    expect(AsignacionResource::getNavigationGroup())->toBe('Operaciones');
});

it('asignacion_resource_navigation_badge_shows_open_count', function () {
    Piv::factory()->create(['piv_id' => 92000]);

    foreach (range(1, 3) as $i) {
        Averia::factory()->create(['averia_id' => 92000 + $i, 'piv_id' => 92000]);
        Asignacion::factory()->create(['asignacion_id' => 92000 + $i, 'averia_id' => 92000 + $i, 'status' => 1]);
    }

    foreach (range(4, 8) as $i) {
        Averia::factory()->create(['averia_id' => 92000 + $i, 'piv_id' => 92000]);
        Asignacion::factory()->create(['asignacion_id' => 92000 + $i, 'averia_id' => 92000 + $i, 'status' => 2]);
    }

    foreach (range(9, 10) as $i) {
        Averia::factory()->create(['averia_id' => 92000 + $i, 'piv_id' => 92000]);
        Asignacion::factory()->create(['asignacion_id' => 92000 + $i, 'averia_id' => 92000 + $i, 'status' => 0]);
    }

    expect(AsignacionResource::getNavigationBadge())->toBe('3');
});

it('asignacion_resource_badge_returns_null_when_no_open', function () {
    expect(AsignacionResource::getNavigationBadge())->toBeNull();
});

it('averia_resource_is_reportes_dual_context_since_bloque_10', function () {
    expect(AveriaResource::shouldRegisterNavigation())->toBeTrue();
    expect(AveriaResource::getNavigationGroup())->toBe('Reportes');
});
