<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Piv;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class AsignacionesAveriasStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $abiertas = Asignacion::where('status', 1);
        $abiertasCorrectivo = (clone $abiertas)->where('tipo', Asignacion::TIPO_CORRECTIVO)->count();
        $abiertasRevision = (clone $abiertas)->where('tipo', Asignacion::TIPO_REVISION)->count();
        $abiertasTotal = $abiertasCorrectivo + $abiertasRevision;

        $startMes = Carbon::now()->startOfMonth();
        $startMesAnt = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $endMesAnt = Carbon::now()->subMonthNoOverflow()->endOfMonth();
        $averiasMes = Averia::where('fecha', '>=', $startMes)->count();
        $averiasMesAnt = Averia::whereBetween('fecha', [$startMesAnt, $endMesAnt])->count();
        $delta = $averiasMesAnt > 0
            ? (int) round((($averiasMes - $averiasMesAnt) / $averiasMesAnt) * 100)
            : null;
        $deltaLabel = $delta === null
            ? 'sin mes anterior comparable'
            : ($delta >= 0 ? "+{$delta}% vs mes anterior" : "{$delta}% vs mes anterior");

        $operativos = Piv::notArchived()->where('status', 1)->count();
        $inactivos = Piv::notArchived()->where('status', 0)->count();
        $totalActivo = $operativos + $inactivos;
        $pctOperativos = $totalActivo > 0
            ? (int) round(($operativos / $totalActivo) * 100)
            : 0;

        return [
            Stat::make('Asignaciones abiertas', (string) $abiertasTotal)
                ->description("{$abiertasCorrectivo} correctivas · {$abiertasRevision} revisiones")
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->color($abiertasTotal > 0 ? 'warning' : 'success'),

            Stat::make('Averías del mes', (string) $averiasMes)
                ->description($deltaLabel)
                ->descriptionIcon($delta !== null && $delta > 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($delta !== null && $delta > 20 ? 'danger' : 'gray'),

            Stat::make('Paneles operativos', "{$operativos} / {$totalActivo}")
                ->description("{$pctOperativos}% del total activo")
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Paneles inactivos', (string) $inactivos)
                ->description('averiados o sin operador')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($inactivos > 50 ? 'danger' : 'warning'),
        ];
    }
}
