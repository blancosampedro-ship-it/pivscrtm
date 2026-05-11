<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LvAveriaIcca;
use App\Models\LvDerivacion;
use App\Models\LvReportePeriodico;
use App\Models\LvRevisionPendiente;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\Piv;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ReportePeriodicoService
{
    public function __construct(private readonly ReportePeriodicoExcelBuilder $excelBuilder) {}

    public function generarMensual(int $anyo, int $mes, User $admin): LvReportePeriodico
    {
        $this->validatePeriod(LvReportePeriodico::TIPO_MENSUAL, $anyo, $mes);

        return $this->generar(LvReportePeriodico::TIPO_MENSUAL, $anyo, $mes, $admin);
    }

    public function generarAnual(int $anyo, User $admin): LvReportePeriodico
    {
        $this->validatePeriod(LvReportePeriodico::TIPO_ANUAL, $anyo, null);

        return $this->generar(LvReportePeriodico::TIPO_ANUAL, $anyo, null, $admin);
    }

    /**
     * Calcula el snapshot contractual del periodo.
     *
     * @read-only No escribe en base de datos ni filesystem.
     *
     * @return array<string, mixed>
     */
    public function calcularKpis(string $tipo, int $anyo, ?int $mes = null): array
    {
        $this->validatePeriod($tipo, $anyo, $mes);
        [$from, $to] = $this->dateRange($tipo, $anyo, $mes);

        $rutaPorMunicipio = $this->rutaPorMunicipio();

        $averiasImportadas = LvAveriaIcca::query()
            ->with(['piv.municipioModulo'])
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $averiasActivasFinPeriodo = LvAveriaIcca::query()
            ->where('created_at', '<=', $to)
            ->where(function (Builder $query) use ($to): void {
                $query->where('activa', true)
                    ->orWhere('marked_inactive_at', '>', $to);
            })
            ->count();

        $revisiones = LvRevisionPendiente::query()
            ->with(['piv.municipioModulo'])
            ->where('periodo_year', $anyo)
            ->when($tipo === LvReportePeriodico::TIPO_MENSUAL, fn (Builder $query): Builder => $query->where('periodo_month', $mes))
            ->get();

        $rutasEjecutadas = LvRutaDia::query()
            ->whereBetween('fecha', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', [LvRutaDia::STATUS_COMPLETADA, LvRutaDia::STATUS_EN_PROGRESO])
            ->count();

        $terminalStatuses = [LvRutaDiaItem::STATUS_CERRADO, LvRutaDiaItem::STATUS_NO_RESUELTO, LvRutaDiaItem::STATUS_DERIVADO];
        $itemsTerminales = LvRutaDiaItem::query()
            ->with(['rutaDia:id,fecha,status', 'averiaIcca.piv.municipioModulo', 'revisionPendiente.piv.municipioModulo', 'derivaciones'])
            ->whereIn('status', $terminalStatuses)
            ->where(function (Builder $query) use ($from, $to): void {
                $query->whereBetween('cerrado_at', [$from, $to])
                    ->orWhere(function (Builder $query) use ($from, $to): void {
                        $query->whereNull('cerrado_at')
                            ->whereHas('rutaDia', fn (Builder $rutaQuery): Builder => $rutaQuery->whereBetween('fecha', [$from->toDateString(), $to->toDateString()]));
                    });
            })
            ->get();

        $derivaciones = LvDerivacion::query()
            ->with(['item.averiaIcca.piv.municipioModulo', 'item.revisionPendiente.piv.municipioModulo'])
            ->whereBetween('fecha_derivacion', [$from, $to])
            ->get();

        $detallePaneles = $this->detallePaneles($averiasImportadas, $revisiones, $derivaciones, $rutaPorMunicipio);
        $itemsPorRuta = $this->itemsPorRuta($itemsTerminales, $rutaPorMunicipio);

        $revisionesPlanificadas = $revisiones->count();
        $revisionesCompletadas = $revisiones->where('status', LvRevisionPendiente::STATUS_COMPLETADA)->count();
        $itemsTotal = $itemsTerminales->count();
        $itemsCerrados = $itemsTerminales->where('status', LvRutaDiaItem::STATUS_CERRADO)->count();
        $itemsNoResueltos = $itemsTerminales->where('status', LvRutaDiaItem::STATUS_NO_RESUELTO)->count();
        $itemsDerivados = $itemsTerminales->where('status', LvRutaDiaItem::STATUS_DERIVADO)->count();

        return [
            'periodo' => [
                'tipo' => $tipo,
                'anyo' => $anyo,
                'mes' => $tipo === LvReportePeriodico::TIPO_MENSUAL ? $mes : null,
                'label' => $this->periodoLabel($tipo, $anyo, $mes),
                'rango_fechas' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            ],
            'volumen' => [
                'averias_icca_importadas' => $averiasImportadas->count(),
                'averias_icca_activas_fin_periodo' => $averiasActivasFinPeriodo,
                'revisiones_planificadas' => $revisionesPlanificadas,
                'revisiones_completadas' => $revisionesCompletadas,
                'rutas_dia_ejecutadas' => $rutasEjecutadas,
                'items_cerrados_total' => $itemsTotal,
                'items_cerrados_correctivos' => $itemsTerminales->where('tipo_item', LvRutaDiaItem::TIPO_CORRECTIVO)->count(),
                'items_cerrados_preventivos' => $itemsTerminales->where('tipo_item', LvRutaDiaItem::TIPO_PREVENTIVO)->count(),
                'items_cerrados_carry' => $itemsTerminales->where('tipo_item', LvRutaDiaItem::TIPO_CARRY_OVER)->count(),
            ],
            'resolucion' => [
                'pct_revisiones_completadas' => $this->percentage($revisionesCompletadas, $revisionesPlanificadas),
                'pct_items_resueltos_en_ruta' => $this->percentage($itemsCerrados, $itemsTotal),
                'pct_items_no_resueltos' => $this->percentage($itemsNoResueltos, $itemsTotal),
                'pct_items_derivados' => $this->percentage($itemsDerivados, $itemsTotal),
                'tiempo_medio_cierre_horas' => $this->averageItemCloseHours($itemsTerminales),
            ],
            'derivaciones' => [
                'total' => $derivaciones->count(),
                'por_causa' => $this->countByKnownValues($derivaciones, 'tipo_causa', LvDerivacion::CAUSAS),
                'por_actor' => $this->countByKnownValues($derivaciones, 'actor_responsable', LvDerivacion::ACTORES),
                'por_status' => $this->countByKnownValues($derivaciones, 'status', [
                    LvDerivacion::STATUS_PENDIENTE_TERCERO,
                    LvDerivacion::STATUS_EN_CURSO,
                    LvDerivacion::STATUS_RESUELTO_EXTERNO,
                    LvDerivacion::STATUS_DEVUELTO_A_RUTA,
                    LvDerivacion::STATUS_CANCELADA,
                ]),
                'tiempo_medio_resolucion_dias' => $this->averageDerivacionResolutionDays($derivaciones),
            ],
            'cobertura_territorial' => [
                'items_por_ruta' => $itemsPorRuta,
                'municipios_top10' => $this->municipiosTop10($itemsTerminales),
            ],
            'detalle_paneles' => $detallePaneles,
            'tablas' => [
                'averias_icca' => $this->averiasTableRows($averiasImportadas),
                'revisiones' => $this->revisionesTableRows($revisiones),
                'derivaciones' => $this->derivacionesTableRows($derivaciones),
            ],
        ];
    }

    private function generar(string $tipo, int $anyo, ?int $mes, User $admin): LvReportePeriodico
    {
        ini_set('memory_limit', '512M');

        [$from, $to] = $this->dateRange($tipo, $anyo, $mes);
        $now = CarbonImmutable::now('Europe/Madrid');

        if ($now->betweenIncluded($from, $to)) {
            Log::warning('Generando reporte periodico de periodo en curso; datos parciales.', [
                'tipo' => $tipo,
                'anyo' => $anyo,
                'mes' => $mes,
            ]);
        }

        $kpis = $this->calcularKpis($tipo, $anyo, $mes);
        $paths = $this->paths($tipo, $anyo, $mes);
        $fullPdfPath = storage_path('app/'.$paths['pdf']);
        $fullXlsxPath = storage_path('app/'.$paths['xlsx']);

        $existing = LvReportePeriodico::query()
            ->where('tipo', $tipo)
            ->where('anyo', $anyo)
            ->where('mes', $mes)
            ->first();

        if ($existing !== null) {
            File::delete([$existing->pdfFullPath(), $existing->xlsxFullPath()]);
        }

        File::ensureDirectoryExists(dirname($fullPdfPath));
        File::ensureDirectoryExists(dirname($fullXlsxPath));

        $pdf = Pdf::loadView('reportes-periodicos.pdf', [
            'kpis' => $kpis,
            'admin' => $admin,
            'generatedAt' => $now,
            'tipoLabel' => $tipo === LvReportePeriodico::TIPO_MENSUAL ? 'MENSUAL' : 'ANUAL',
        ])->setPaper('a4', 'portrait');

        File::put($fullPdfPath, $pdf->output());
        $this->excelBuilder->save($kpis, $fullXlsxPath);

        return LvReportePeriodico::query()->updateOrCreate(
            ['tipo' => $tipo, 'anyo' => $anyo, 'mes' => $mes],
            [
                'generated_at' => $now,
                'generated_by_user_id' => $admin->id,
                'pdf_path' => $paths['pdf'],
                'xlsx_path' => $paths['xlsx'],
                'metadata_json' => $kpis,
            ]
        );
    }

    private function validatePeriod(string $tipo, int $anyo, ?int $mes): void
    {
        if (! in_array($tipo, LvReportePeriodico::TIPOS, true)) {
            throw new DomainException('Tipo de reporte no soportado.');
        }

        $now = CarbonImmutable::now('Europe/Madrid');
        if ($anyo < 2024 || $anyo > $now->year + 1) {
            throw new DomainException('Año fuera de rango permitido.');
        }

        if ($tipo === LvReportePeriodico::TIPO_MENSUAL && ($mes === null || $mes < 1 || $mes > 12)) {
            throw new DomainException('Mes fuera de rango permitido.');
        }

        [$from] = $this->dateRange($tipo, $anyo, $tipo === LvReportePeriodico::TIPO_ANUAL ? null : $mes);
        if ($from->startOfDay()->greaterThan($now->endOfDay())) {
            throw new DomainException('No se puede generar un reporte de un periodo futuro.');
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function dateRange(string $tipo, int $anyo, ?int $mes): array
    {
        if ($tipo === LvReportePeriodico::TIPO_ANUAL) {
            $from = CarbonImmutable::create($anyo, 1, 1, 0, 0, 0, 'Europe/Madrid');

            return [$from, $from->endOfYear()];
        }

        $from = CarbonImmutable::create($anyo, $mes ?? 1, 1, 0, 0, 0, 'Europe/Madrid');

        return [$from, $from->endOfMonth()];
    }

    private function periodoLabel(string $tipo, int $anyo, ?int $mes): string
    {
        if ($tipo === LvReportePeriodico::TIPO_ANUAL) {
            return 'Año '.$anyo;
        }

        $date = CarbonImmutable::create($anyo, $mes ?? 1, 1, 0, 0, 0, 'Europe/Madrid')->locale('es');

        return ucfirst($date->translatedFormat('F Y'));
    }

    /** @return array{pdf: string, xlsx: string} */
    private function paths(string $tipo, int $anyo, ?int $mes): array
    {
        $directory = "reportes-periodicos/{$anyo}/{$tipo}";

        if ($tipo === LvReportePeriodico::TIPO_ANUAL) {
            return [
                'pdf' => "{$directory}/reporte-anual-{$anyo}.pdf",
                'xlsx' => "{$directory}/reporte-anual-{$anyo}.xlsx",
            ];
        }

        $month = str_pad((string) $mes, 2, '0', STR_PAD_LEFT);

        return [
            'pdf' => "{$directory}/reporte-mensual-{$anyo}-{$month}.pdf",
            'xlsx' => "{$directory}/reporte-mensual-{$anyo}-{$month}.xlsx",
        ];
    }

    private function percentage(int $value, int $total): float
    {
        return $total === 0 ? 0.0 : round(($value / $total) * 100, 2);
    }

    private function averageItemCloseHours(Collection $items): float
    {
        $hours = $items
            ->filter(fn (LvRutaDiaItem $item): bool => $item->created_at !== null && $item->cerrado_at !== null)
            ->map(fn (LvRutaDiaItem $item): float => $item->created_at->floatDiffInHours($item->cerrado_at));

        return $hours->isEmpty() ? 0.0 : round((float) $hours->avg(), 2);
    }

    private function averageDerivacionResolutionDays(Collection $derivaciones): float
    {
        $days = $derivaciones
            ->filter(fn (LvDerivacion $derivacion): bool => $derivacion->fecha_derivacion !== null && $derivacion->fecha_resolucion !== null)
            ->map(fn (LvDerivacion $derivacion): float => $derivacion->fecha_derivacion->floatDiffInDays($derivacion->fecha_resolucion));

        return $days->isEmpty() ? 0.0 : round((float) $days->avg(), 2);
    }

    /** @param list<string> $knownValues */
    private function countByKnownValues(Collection $records, string $field, array $knownValues): array
    {
        $counts = array_fill_keys($knownValues, 0);

        foreach ($records as $record) {
            $value = (string) $record->{$field};
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        return $counts;
    }

    private function rutaPorMunicipio(): array
    {
        return PivRutaMunicipio::query()
            ->with('ruta:id,codigo')
            ->get()
            ->mapWithKeys(fn (PivRutaMunicipio $row): array => [$row->municipio_modulo_id => $row->ruta?->codigo ?? 'sin_ruta'])
            ->all();
    }

    private function itemsPorRuta(Collection $items, array $rutaPorMunicipio): array
    {
        $counts = array_fill_keys([...PivRuta::CODIGOS, 'sin_ruta'], 0);

        foreach ($items as $item) {
            $piv = $this->pivFromItem($item);
            $ruta = $piv instanceof Piv ? ($rutaPorMunicipio[(int) $piv->municipio] ?? 'sin_ruta') : 'sin_ruta';
            $counts[$ruta] = ($counts[$ruta] ?? 0) + 1;
        }

        return $counts;
    }

    private function municipiosTop10(Collection $items): array
    {
        return $items
            ->map(fn (LvRutaDiaItem $item): string => $this->municipioLabel($this->pivFromItem($item)))
            ->countBy()
            ->sortDesc()
            ->take(10)
            ->map(fn (int $count, string $municipio): array => ['municipio' => $municipio, 'items' => $count])
            ->values()
            ->all();
    }

    private function detallePaneles(Collection $averias, Collection $revisiones, Collection $derivaciones, array $rutaPorMunicipio): array
    {
        $rows = [];

        foreach ($averias as $averia) {
            $key = $this->panelKey($averia->piv);
            $rows[$key] ??= $this->emptyPanelRow($averia->piv, $rutaPorMunicipio);
            $rows[$key]['averias_icca']++;
        }

        foreach ($revisiones->where('status', LvRevisionPendiente::STATUS_COMPLETADA) as $revision) {
            $key = $this->panelKey($revision->piv);
            $rows[$key] ??= $this->emptyPanelRow($revision->piv, $rutaPorMunicipio);
            $rows[$key]['revisiones_completadas']++;
        }

        foreach ($derivaciones as $derivacion) {
            $piv = $derivacion->item ? $this->pivFromItem($derivacion->item) : null;
            $key = $this->panelKey($piv);
            $rows[$key] ??= $this->emptyPanelRow($piv, $rutaPorMunicipio);
            $rows[$key]['derivaciones']++;
        }

        return array_values($rows);
    }

    private function emptyPanelRow(?Piv $piv, array $rutaPorMunicipio): array
    {
        return [
            'piv_id' => $piv?->piv_id,
            'parada_cod' => $piv?->parada_cod ?? 'sin_panel',
            'municipio' => $this->municipioLabel($piv),
            'ruta' => $piv instanceof Piv ? ($rutaPorMunicipio[(int) $piv->municipio] ?? 'sin_ruta') : 'sin_ruta',
            'averias_icca' => 0,
            'revisiones_completadas' => 0,
            'derivaciones' => 0,
        ];
    }

    private function panelKey(?Piv $piv): string
    {
        return $piv instanceof Piv ? 'piv_'.$piv->piv_id : 'sin_panel';
    }

    private function pivFromItem(LvRutaDiaItem $item): ?Piv
    {
        if ($item->averiaIcca !== null) {
            return $item->averiaIcca->piv;
        }

        if ($item->revisionPendiente !== null) {
            return $item->revisionPendiente->piv;
        }

        return null;
    }

    private function municipioLabel(?Piv $piv): string
    {
        return $piv?->municipioModulo?->nombre ?? 'sin_municipio';
    }

    private function panelLabel(?Piv $piv): string
    {
        return $piv?->parada_cod ?? 'sin_panel';
    }

    private function averiasTableRows(Collection $averias): array
    {
        return $averias->map(fn (LvAveriaIcca $averia): array => [
            'sgip_id' => $averia->sgip_id,
            'panel' => $averia->piv?->parada_cod ?? $averia->panel_id_sgip,
            'municipio' => $this->municipioLabel($averia->piv),
            'categoria' => $averia->categoria,
            'descripcion' => Str::limit((string) $averia->descripcion, 500, ''),
            'fecha_import' => $averia->fecha_import?->format('Y-m-d H:i') ?? '',
            'activa' => $averia->activa ? 'Sí' : 'No',
            'resuelto' => $averia->activa ? 'No' : 'Sí',
        ])->values()->all();
    }

    private function revisionesTableRows(Collection $revisiones): array
    {
        return $revisiones->map(fn (LvRevisionPendiente $revision): array => [
            'piv_id' => $revision->piv_id,
            'parada' => $this->panelLabel($revision->piv),
            'municipio' => $this->municipioLabel($revision->piv),
            'periodo' => sprintf('%04d-%02d', $revision->periodo_year, $revision->periodo_month),
            'status' => $revision->status,
            'fecha_planificada' => $revision->fecha_planificada?->format('Y-m-d') ?? '',
            'decision' => $revision->decision_at?->format('Y-m-d H:i') ?? '',
            'asignacion_creada' => $revision->asignacion_id !== null ? 'Sí' : 'No',
        ])->values()->all();
    }

    private function derivacionesTableRows(Collection $derivaciones): array
    {
        return $derivaciones->map(function (LvDerivacion $derivacion): array {
            $piv = $derivacion->item ? $this->pivFromItem($derivacion->item) : null;

            return [
                'item_id' => $derivacion->lv_ruta_dia_item_id,
                'panel' => $this->panelLabel($piv),
                'causa' => $derivacion->tipo_causa,
                'actor' => $derivacion->actor_responsable,
                'notas_actor' => $derivacion->actor_notas,
                'status' => $derivacion->status,
                'fecha_derivacion' => $derivacion->fecha_derivacion?->format('Y-m-d H:i') ?? '',
                'fecha_resolucion' => $derivacion->fecha_resolucion?->format('Y-m-d H:i') ?? '',
            ];
        })->values()->all();
    }
}
