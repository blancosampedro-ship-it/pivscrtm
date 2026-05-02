<?php

declare(strict_types=1);

use App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones;
use App\Filament\Resources\AveriaResource;
use App\Filament\Resources\AveriaResource\Pages\ListAverias;
use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

function streamedContent(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

function downloadedCsvContent($component): string
{
    return (string) base64_decode(data_get($component->effects, 'download.content'), true);
}

it('averia_resource_visible_in_sidebar_under_reportes', function () {
    expect(AveriaResource::shouldRegisterNavigation())->toBeTrue();
    expect(AveriaResource::getNavigationGroup())->toBe('Reportes');
});

it('averia_csv_export_includes_tecnico_fields_for_admin_path', function () {
    $tecnico = Tecnico::factory()->create([
        'nombre_completo' => 'Test Técnico',
        'dni' => '99999999X',
        'email' => 'test@tecnico.com',
    ]);
    $piv = Piv::factory()->create();
    $averia = Averia::factory()->create(['piv_id' => $piv->piv_id]);
    Asignacion::factory()->create([
        'averia_id' => $averia->averia_id,
        'tecnico_id' => $tecnico->tecnico_id,
    ]);

    $component = Livewire::test(ListAverias::class)
        ->callAction('export')
        ->assertFileDownloaded('averias-'.now()->format('Y-m-d').'.csv');
    $csv = downloadedCsvContent($component);

    expect($csv)->toContain('Test Técnico');
    expect($csv)->toContain('99999999X');
    expect($csv)->toContain('test@tecnico.com');
});

it('averia_csv_export_response_is_streamed', function () {
    $response = Livewire::test(ListAverias::class)
        ->instance()
        ->exportCsv();

    expect($response)->toBeInstanceOf(StreamedResponse::class);
});

it('asignacion_csv_export_includes_tecnico_fields_for_admin_path', function () {
    $tecnico = Tecnico::factory()->create([
        'nombre_completo' => 'Export Técnico',
        'dni' => '11111111H',
        'email' => 'export@tecnico.com',
    ]);
    $piv = Piv::factory()->create();
    $averia = Averia::factory()->create(['piv_id' => $piv->piv_id]);
    Asignacion::factory()->create([
        'averia_id' => $averia->averia_id,
        'tecnico_id' => $tecnico->tecnico_id,
        'tipo' => Asignacion::TIPO_CORRECTIVO,
    ]);

    $component = Livewire::test(ListAsignaciones::class)
        ->callAction('export')
        ->assertFileDownloaded('asignaciones-'.now()->format('Y-m-d').'.csv');
    $csv = downloadedCsvContent($component);

    expect($csv)->toContain('Export Técnico');
    expect($csv)->toContain('11111111H');
    expect($csv)->toContain('export@tecnico.com');
    expect($csv)->toContain('Correctivo');
});
