<?php

declare(strict_types=1);

use App\Filament\Widgets\AsignacionesAveriasStatsOverview;
use App\Filament\Widgets\CargaPorTecnicoWidget;
use App\Filament\Widgets\TopPanelesIncidenciaWidget;
use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\LvPivArchived;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('dashboard_asignaciones_abiertas_breakdown_by_tipo — stat correctivo only counts tipo=1', function () {
    $piv = Piv::factory()->create();

    foreach (range(1, 3) as $i) {
        $averia = Averia::factory()->create(['piv_id' => $piv->piv_id]);
        Asignacion::factory()->create([
            'averia_id' => $averia->averia_id,
            'tipo' => Asignacion::TIPO_CORRECTIVO,
            'status' => 1,
        ]);
    }

    foreach (range(1, 2) as $i) {
        $averia = Averia::factory()->create(['piv_id' => $piv->piv_id]);
        Asignacion::factory()->create([
            'averia_id' => $averia->averia_id,
            'tipo' => Asignacion::TIPO_REVISION,
            'status' => 1,
        ]);
    }

    $averia = Averia::factory()->create(['piv_id' => $piv->piv_id]);
    Asignacion::factory()->create([
        'averia_id' => $averia->averia_id,
        'tipo' => Asignacion::TIPO_CORRECTIVO,
        'status' => 2,
    ]);

    Livewire::test(AsignacionesAveriasStatsOverview::class)
        ->assertSee('5')
        ->assertSee('3 correctivas · 2 revisiones');
});

it('dashboard_kpi_filters_only_by_tipo', function () {
    $source = file_get_contents(app_path('Filament/Widgets/AsignacionesAveriasStatsOverview.php'));

    expect($source)->toContain("->where('tipo', Asignacion::TIPO_CORRECTIVO)");
    expect($source)->toContain("->where('tipo', Asignacion::TIPO_REVISION)");
    expect(mb_strtolower($source))->not->toContain('notas');
    expect(mb_strtolower($source))->not->toContain('revision mensual');
});

it('dashboard_top_paneles_excludes_archived', function () {
    $pivOk = Piv::factory()->create(['piv_id' => 99001]);
    $pivArchived = Piv::factory()->create(['piv_id' => 99002]);

    foreach (range(1, 5) as $i) {
        Averia::factory()->create(['piv_id' => $pivOk->piv_id, 'fecha' => now()->subWeek()]);
        Averia::factory()->create(['piv_id' => $pivArchived->piv_id, 'fecha' => now()->subWeek()]);
    }

    LvPivArchived::create([
        'piv_id' => $pivArchived->piv_id,
        'archived_at' => now(),
        'archived_by_user_id' => User::factory()->admin()->create()->id,
    ]);

    Livewire::test(TopPanelesIncidenciaWidget::class)
        ->assertCanSeeTableRecords([$pivOk])
        ->assertCanNotSeeTableRecords([$pivArchived]);
});

it('dashboard_carga_por_tecnico_excludes_inactive_tecnicos', function () {
    $tecnicoActivo = Tecnico::factory()->create(['nombre_completo' => 'Pepe Activo', 'status' => 1]);
    $tecnicoInactivo = Tecnico::factory()->create(['nombre_completo' => 'Mario Inactivo', 'status' => 0]);

    $piv = Piv::factory()->create();
    $averiaActiva = Averia::factory()->create(['piv_id' => $piv->piv_id]);
    Asignacion::factory()->create([
        'averia_id' => $averiaActiva->averia_id,
        'tecnico_id' => $tecnicoActivo->tecnico_id,
        'status' => 1,
    ]);

    $averiaInactiva = Averia::factory()->create(['piv_id' => $piv->piv_id]);
    Asignacion::factory()->create([
        'averia_id' => $averiaInactiva->averia_id,
        'tecnico_id' => $tecnicoInactivo->tecnico_id,
        'status' => 1,
    ]);

    Livewire::test(CargaPorTecnicoWidget::class)
        ->assertCanSeeTableRecords([$tecnicoActivo])
        ->assertCanNotSeeTableRecords([$tecnicoInactivo]);
});
