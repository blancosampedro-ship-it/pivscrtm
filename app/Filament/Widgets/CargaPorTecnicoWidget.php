<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Asignacion;
use App\Models\Tecnico;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class CargaPorTecnicoWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Carga por técnico (asignaciones abiertas)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Tecnico::query()
                    ->where('status', 1)
                    ->withCount([
                        'asignaciones as abiertas_count' => fn (Builder $query) => $query->where('status', 1),
                        'asignaciones as correctivos_count' => fn (Builder $query) => $query
                            ->where('status', 1)
                            ->where('tipo', Asignacion::TIPO_CORRECTIVO),
                        'asignaciones as revisiones_count' => fn (Builder $query) => $query
                            ->where('status', 1)
                            ->where('tipo', Asignacion::TIPO_REVISION),
                    ])
                    ->orderByDesc('abiertas_count')
            )
            ->columns([
                Tables\Columns\TextColumn::make('nombre_completo')
                    ->label('Técnico'),
                Tables\Columns\TextColumn::make('abiertas_count')
                    ->label('Total abiertas')
                    ->badge()
                    ->color(fn ($state) => $state > 5 ? 'danger' : ($state > 0 ? 'warning' : 'success'))
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('correctivos_count')
                    ->label('Correctivos')
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('revisiones_count')
                    ->label('Revisiones')
                    ->extraAttributes(['data-mono' => true]),
            ]);
    }
}
