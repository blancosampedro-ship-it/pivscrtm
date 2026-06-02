<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\PivResource;
use App\Models\Piv;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TopPanelesIncidenciaWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Top 5 paneles con más incidencias (6 meses)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $sixMonthsAgo = Carbon::now()->subMonths(6);

        // Agregamos averías por panel en UNA pasada (GROUP BY) y la unimos a piv, en vez de una
        // subconsulta correlacionada por cada panel (withCount + orderBy), que escaneaba `averia`
        // una vez por panel. Sobre datos reales esto bajó el widget de ~5.5 s a ~40 ms.
        $incidencias = DB::table('averia')
            ->select('piv_id', DB::raw('COUNT(*) as incidencias_6m_count'))
            ->where('fecha', '>=', $sixMonthsAgo)
            ->groupBy('piv_id');

        return $table
            ->query(
                Piv::query()
                    ->notArchived()
                    ->joinSub($incidencias, 'agg', 'agg.piv_id', '=', 'piv.piv_id')
                    ->with(['municipioModulo:modulo_id,nombre'])
                    ->select('piv.*', 'agg.incidencias_6m_count')
                    ->orderByDesc('agg.incidencias_6m_count')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('piv_id')
                    ->label('ID')
                    ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 3, '0', STR_PAD_LEFT))
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('parada_cod')
                    ->label('Parada')
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('direccion')
                    ->label('Dirección')
                    ->limit(40),
                Tables\Columns\TextColumn::make('municipioModulo.nombre')
                    ->label('Municipio')
                    ->default('—'),
                Tables\Columns\TextColumn::make('incidencias_6m_count')
                    ->label('Averías 6m')
                    ->badge()
                    ->color('danger')
                    ->extraAttributes(['data-mono' => true]),
            ])
            ->recordUrl(fn (Piv $record): string => PivResource::getUrl('view', ['record' => $record]));
    }
}
