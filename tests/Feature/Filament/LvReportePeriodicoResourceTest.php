<?php

declare(strict_types=1);

use App\Filament\Resources\LvReportePeriodicoResource;
use App\Filament\Resources\LvReportePeriodicoResource\Pages\ListLvReportesPeriodicos;
use App\Models\LvReportePeriodico;
use App\Models\User;
use App\Services\ReportePeriodicoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-11 10:00:00', 'Europe/Madrid'));
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('list page accesible y slug explicito', function (): void {
    LvReportePeriodico::factory()->create();

    $this->get(LvReportePeriodicoResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Mayo 2026');

    expect(LvReportePeriodicoResource::getSlug())->toBe('reportes-periodicos');
    expect(LvReportePeriodicoResource::getNavigationGroup())->toBe('Planificación');
    expect(LvReportePeriodicoResource::getNavigationLabel())->toBe('Reportes');
});

it('header action Generar reporte valida mes requerido si mensual', function (): void {
    Livewire::test(ListLvReportesPeriodicos::class)
        ->callTableAction('generarReporte', data: [
            'tipo' => LvReportePeriodico::TIPO_MENSUAL,
            'anyo' => 2026,
        ])
        ->assertHasTableActionErrors(['mes' => 'required']);
});

it('header action Generar reporte crea reporte mensual', function (): void {
    Livewire::test(ListLvReportesPeriodicos::class)
        ->callTableAction('generarReporte', data: [
            'tipo' => LvReportePeriodico::TIPO_MENSUAL,
            'anyo' => 2026,
            'mes' => 4,
        ])
        ->assertHasNoTableActionErrors();

    $report = LvReportePeriodico::query()->firstOrFail();
    expect(File::exists($report->pdfFullPath()))->toBeTrue();
    expect(File::exists($report->xlsxFullPath()))->toBeTrue();
});

it('descarga PDF devuelve binary response', function (): void {
    $report = app(ReportePeriodicoService::class)->generarMensual(2026, 4, $this->admin);

    Livewire::test(ListLvReportesPeriodicos::class)
        ->callTableAction('descargarPdf', $report)
        ->assertFileDownloaded('reporte-mensual-2026-04.pdf');
});

it('regenerar es idempotente', function (): void {
    $report = app(ReportePeriodicoService::class)->generarMensual(2026, 4, $this->admin);

    Livewire::test(ListLvReportesPeriodicos::class)
        ->callTableAction('regenerar', $report)
        ->assertHasNoTableActionErrors();

    expect(LvReportePeriodico::query()->where('tipo', 'mensual')->where('anyo', 2026)->where('mes', 4)->count())->toBe(1);
});
