<?php

declare(strict_types=1);

use App\Models\LvAveriaIcca;
use App\Models\LvDerivacion;
use App\Models\LvReportePeriodico;
use App\Models\LvRevisionPendiente;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\Modulo;
use App\Models\Piv;
use App\Models\User;
use App\Services\ReportePeriodicoService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-11 10:00:00', 'Europe/Madrid'));
    $this->service = app(ReportePeriodicoService::class);
    $this->admin = User::factory()->admin()->create(['name' => 'Admin Winfin']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('tabla creada con unique compuesto y metadata_json casteado a array', function (): void {
    expect(DB::getSchemaBuilder()->hasTable('lv_reporte_periodico'))->toBeTrue();
    expect(DB::getSchemaBuilder()->hasColumns('lv_reporte_periodico', [
        'tipo', 'anyo', 'mes', 'generated_at', 'generated_by_user_id', 'pdf_path', 'xlsx_path', 'metadata_json',
    ]))->toBeTrue();

    $report = LvReportePeriodico::factory()->create();

    expect($report->metadata_json)->toBeArray();
    expect($report->periodoLabel())->toBe('Mayo 2026');
    expect($report->pdfFullPath())->toEndWith('storage/app/reportes-periodicos/2026/mensual/reporte-mensual-2026-05.pdf');
    expect(LvReportePeriodico::query()->mensuales()->count())->toBe(1);
    expect(LvReportePeriodico::query()->delAnyo(2026)->count())->toBe(1);

    LvReportePeriodico::factory()->anual()->create();
    expect(LvReportePeriodico::query()->anuales()->first()->periodoLabel())->toBe('Año 2026');

    expect(fn () => LvReportePeriodico::factory()->create())->toThrow(QueryException::class);
});

it('calcularKpis mensual devuelve estructura completa y cuenta datos mezclados', function (): void {
    $piv = pivConMunicipio('Móstoles');
    LvAveriaIcca::factory()->count(5)->create(['piv_id' => $piv->piv_id, 'created_at' => '2026-05-03 10:00:00', 'fecha_import' => '2026-05-03 10:00:00']);
    LvAveriaIcca::factory()->count(3)->create(['piv_id' => $piv->piv_id, 'created_at' => '2026-04-20 10:00:00', 'fecha_import' => '2026-04-20 10:00:00']);
    foreach (range(1, 3) as $unused) {
        LvRevisionPendiente::factory()->completada()->create(['piv_id' => pivConMunicipio('Móstoles')->piv_id, 'periodo_year' => 2026, 'periodo_month' => 5]);
    }
    foreach (range(1, 2) as $unused) {
        LvRevisionPendiente::factory()->pendiente()->create(['piv_id' => pivConMunicipio('Móstoles')->piv_id, 'periodo_year' => 2026, 'periodo_month' => 5]);
    }
    LvRevisionPendiente::factory()->create(['piv_id' => $piv->piv_id, 'periodo_year' => 2026, 'periodo_month' => 4]);

    $rutaMayo = LvRutaDia::factory()->completada()->create(['fecha' => '2026-05-05']);
    LvRutaDia::factory()->completada()->create(['fecha' => '2026-04-05']);
    $itemAveria = LvAveriaIcca::factory()->create(['piv_id' => $piv->piv_id, 'created_at' => '2026-04-01 10:00:00', 'fecha_import' => '2026-04-01 10:00:00']);
    LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $rutaMayo->id,
        'lv_averia_icca_id' => $itemAveria->id,
        'status' => LvRutaDiaItem::STATUS_CERRADO,
        'created_at' => '2026-05-05 08:00:00',
        'cerrado_at' => '2026-05-05 12:00:00',
    ]);
    $derivacionItem1 = LvRutaDiaItem::factory()->create(['ruta_dia_id' => $rutaMayo->id, 'lv_averia_icca_id' => $itemAveria->id]);
    $derivacionItem2 = LvRutaDiaItem::factory()->create(['ruta_dia_id' => $rutaMayo->id, 'lv_averia_icca_id' => $itemAveria->id]);
    $derivacionItem3 = LvRutaDiaItem::factory()->create(['ruta_dia_id' => $rutaMayo->id, 'lv_averia_icca_id' => $itemAveria->id]);
    LvDerivacion::factory()->create(['lv_ruta_dia_item_id' => $derivacionItem1->id, 'status' => LvDerivacion::STATUS_PENDIENTE_TERCERO, 'fecha_derivacion' => '2026-05-06 10:00:00']);
    LvDerivacion::factory()->cerrada(LvDerivacion::STATUS_RESUELTO_EXTERNO)->create(['lv_ruta_dia_item_id' => $derivacionItem2->id, 'fecha_derivacion' => '2026-05-07 10:00:00', 'fecha_resolucion' => '2026-05-09 10:00:00']);
    LvDerivacion::factory()->create(['lv_ruta_dia_item_id' => $derivacionItem3->id, 'status' => LvDerivacion::STATUS_EN_CURSO, 'fecha_derivacion' => '2026-04-07 10:00:00']);

    $kpis = $this->service->calcularKpis(LvReportePeriodico::TIPO_MENSUAL, 2026, 5);

    expect($kpis)->toHaveKeys(['periodo', 'volumen', 'resolucion', 'derivaciones', 'cobertura_territorial', 'detalle_paneles', 'tablas']);
    expect($kpis['volumen']['averias_icca_importadas'])->toBe(5);
    expect($kpis['volumen']['revisiones_planificadas'])->toBe(5);
    expect($kpis['volumen']['revisiones_completadas'])->toBe(3);
    expect($kpis['volumen']['rutas_dia_ejecutadas'])->toBe(1);
    expect($kpis['volumen']['items_cerrados_total'])->toBe(1);
    expect($kpis['derivaciones']['por_status'][LvDerivacion::STATUS_PENDIENTE_TERCERO])->toBe(1);
    expect($kpis['derivaciones']['por_status'][LvDerivacion::STATUS_RESUELTO_EXTERNO])->toBe(1);
    expect($kpis['resolucion']['tiempo_medio_cierre_horas'])->toBe(4.0);
    expect($kpis['derivaciones']['tiempo_medio_resolucion_dias'])->toBe(2.0);
});

it('anual agrega los doce meses', function (): void {
    $piv = pivConMunicipio('Getafe');
    LvAveriaIcca::factory()->create(['piv_id' => $piv->piv_id, 'created_at' => '2026-01-03 10:00:00', 'fecha_import' => '2026-01-03 10:00:00']);
    LvAveriaIcca::factory()->create(['piv_id' => $piv->piv_id, 'created_at' => '2026-12-03 10:00:00', 'fecha_import' => '2026-12-03 10:00:00']);
    LvAveriaIcca::factory()->create(['piv_id' => $piv->piv_id, 'created_at' => '2025-12-03 10:00:00', 'fecha_import' => '2025-12-03 10:00:00']);
    LvRevisionPendiente::factory()->completada()->create(['piv_id' => $piv->piv_id, 'periodo_year' => 2026, 'periodo_month' => 1]);
    LvRevisionPendiente::factory()->completada()->create(['piv_id' => $piv->piv_id, 'periodo_year' => 2026, 'periodo_month' => 12]);

    $kpis = $this->service->calcularKpis(LvReportePeriodico::TIPO_ANUAL, 2026);

    expect($kpis['periodo']['label'])->toBe('Año 2026');
    expect($kpis['volumen']['averias_icca_importadas'])->toBe(2);
    expect($kpis['volumen']['revisiones_completadas'])->toBe(2);
});

it('periodo futuro lanza DomainException', function (): void {
    expect(fn () => $this->service->generarMensual(2026, 12, $this->admin))->toThrow(DomainException::class);
});

it('periodo en curso se permite y loggea warning', function (): void {
    Log::shouldReceive('warning')->once();

    $report = $this->service->generarMensual(2026, 5, $this->admin);

    expect($report->exists)->toBeTrue();
    expect(File::exists($report->pdfFullPath()))->toBeTrue();
    expect(File::exists($report->xlsxFullPath()))->toBeTrue();
});

it('generarMensual crea archivos y regenerar actualiza sin duplicar', function (): void {
    $first = $this->service->generarMensual(2026, 4, $this->admin);
    $firstPdfPath = $first->pdfFullPath();

    File::put($firstPdfPath, 'archivo anterior');
    $second = $this->service->generarMensual(2026, 4, $this->admin);

    expect($second->id)->toBe($first->id);
    expect(LvReportePeriodico::query()->where('tipo', 'mensual')->where('anyo', 2026)->where('mes', 4)->count())->toBe(1);
    expect(File::get($second->pdfFullPath()))->not->toBe('archivo anterior');
});

it('calcula KPIs cuando no hay datos del periodo con zeros sin crash', function (): void {
    $kpis = $this->service->calcularKpis(LvReportePeriodico::TIPO_MENSUAL, 2026, 4);

    expect($kpis['volumen']['averias_icca_importadas'])->toBe(0);
    expect($kpis['resolucion']['pct_revisiones_completadas'])->toBe(0.0);
    expect($kpis['resolucion']['tiempo_medio_cierre_horas'])->toBe(0.0);
    expect($kpis['derivaciones']['tiempo_medio_resolucion_dias'])->toBe(0.0);
});

it('acentos UTF-8 en municipios se preservan en Excel y metadata', function (): void {
    $mostoles = pivConMunicipio('Móstoles');
    $pozuelo = pivConMunicipio('Pozuelo de Alarcón');
    LvAveriaIcca::factory()->create(['piv_id' => $mostoles->piv_id, 'created_at' => '2026-04-03 10:00:00', 'fecha_import' => '2026-04-03 10:00:00']);
    LvRevisionPendiente::factory()->completada()->create(['piv_id' => $pozuelo->piv_id, 'periodo_year' => 2026, 'periodo_month' => 4]);

    $report = $this->service->generarMensual(2026, 4, $this->admin);
    $spreadsheet = IOFactory::load($report->xlsxFullPath());
    $xlsxText = json_encode($spreadsheet->getSheetByName('Detalle paneles')->toArray(), JSON_UNESCAPED_UNICODE);

    expect($xlsxText)->toContain('Móstoles');
    expect($xlsxText)->toContain('Pozuelo de Alarcón');
    expect(json_encode($report->metadata_json, JSON_UNESCAPED_UNICODE))->toContain('Móstoles');
});

it('item con piv_id NULL no rompe detalle_paneles y agrupa sin_panel', function (): void {
    $ruta = LvRutaDia::factory()->completada()->create(['fecha' => '2026-04-10']);
    LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'status' => LvRutaDiaItem::STATUS_CERRADO,
        'cerrado_at' => '2026-04-10 12:00:00',
    ]);

    $kpis = $this->service->calcularKpis(LvReportePeriodico::TIPO_MENSUAL, 2026, 4);

    expect($kpis['cobertura_territorial']['items_por_ruta']['sin_ruta'])->toBe(1);
});

it('derivacion con causa_otros_texto se contabiliza en otros', function (): void {
    LvDerivacion::factory()->create([
        'tipo_causa' => LvDerivacion::CAUSA_OTROS,
        'causa_otros_texto' => 'Caso especial',
        'fecha_derivacion' => '2026-04-10 12:00:00',
    ]);

    $kpis = $this->service->calcularKpis(LvReportePeriodico::TIPO_MENSUAL, 2026, 4);

    expect($kpis['derivaciones']['por_causa'][LvDerivacion::CAUSA_OTROS])->toBe(1);
});

it('calcularKpis no escribe en BD', function (): void {
    LvAveriaIcca::factory()->create(['created_at' => '2026-04-03 10:00:00', 'fecha_import' => '2026-04-03 10:00:00']);
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower(ltrim($query->sql));
    });

    $this->service->calcularKpis(LvReportePeriodico::TIPO_MENSUAL, 2026, 4);

    expect($queries)->not->toBeEmpty();
    expect(collect($queries)->filter(fn (string $sql): bool => preg_match('/^(insert|update|delete|replace|alter|drop|create|truncate)\b/', $sql) === 1))->toBeEmpty();
});

it('reporte generado no contiene campos RGPD de tecnico', function (): void {
    $report = $this->service->generarMensual(2026, 4, $this->admin);
    $html = strtolower(view('reportes-periodicos.pdf', [
        'kpis' => $report->metadata_json,
        'admin' => $this->admin,
        'generatedAt' => CarbonImmutable::parse($report->generated_at),
        'tipoLabel' => 'MENSUAL',
    ])->render());
    $spreadsheet = IOFactory::load($report->xlsxFullPath());
    $xlsx = strtolower(json_encode($spreadsheet->getActiveSheet()->toArray(), JSON_UNESCAPED_UNICODE));
    $metadata = strtolower(json_encode($report->metadata_json));

    foreach (['dni', 'n_seguridad_social', 'ccc', 'telefono', 'direccion', 'email'] as $forbidden) {
        expect($html)->not->toContain($forbidden);
        expect($xlsx)->not->toContain($forbidden);
        expect($metadata)->not->toContain($forbidden);
    }
});

function pivConMunicipio(string $municipioNombre): Piv
{
    $municipio = Modulo::factory()->municipio($municipioNombre)->create();

    return Piv::factory()->create(['municipio' => (string) $municipio->modulo_id]);
}
