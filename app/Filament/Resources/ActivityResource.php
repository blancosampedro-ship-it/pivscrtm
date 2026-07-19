<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * M2 — Registro de auditoría: quién hizo qué (solo lectura).
 * El log lo escriben los modelos vía LogsActivity; aquí solo se consulta.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $modelLabel = 'actividad';

    protected static ?string $pluralModelLabel = 'registro de actividad';

    protected static ?string $navigationLabel = 'Actividad';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?string $slug = 'actividad';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('causer:id,name,email');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d M Y · H:i:s')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Usuario')
                    ->placeholder('— sistema —')
                    ->searchable(),
                Tables\Columns\TextColumn::make('event')
                    ->label('Acción')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'created' => 'Alta',
                        'updated' => 'Edición',
                        'deleted' => 'Borrado',
                        default => $state ?? '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'info',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('log_name')
                    ->label('Módulo')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('subject_id')
                    ->label('Registro')
                    ->formatStateUsing(fn (Activity $record): string => class_basename((string) $record->subject_type).' #'.$record->subject_id)
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('properties')
                    ->label('Cambios')
                    ->formatStateUsing(function (Activity $record): string {
                        $attrs = array_keys((array) ($record->properties['attributes'] ?? []));

                        return $attrs === [] ? '—' : implode(', ', array_slice($attrs, 0, 4)).(count($attrs) > 4 ? '…' : '');
                    })
                    ->limit(60)
                    ->color('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('log_name')
                    ->label('Módulo')
                    ->options(fn (): array => Activity::query()
                        ->select('log_name')
                        ->distinct()
                        ->orderBy('log_name')
                        ->pluck('log_name', 'log_name')
                        ->all()),
                Tables\Filters\SelectFilter::make('event')
                    ->label('Acción')
                    ->options(['created' => 'Alta', 'updated' => 'Edición', 'deleted' => 'Borrado']),
                Tables\Filters\Filter::make('fecha')
                    ->form([
                        DatePicker::make('desde'),
                        DatePicker::make('hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate('created_at', '>=', $d))
                        ->when($data['hasta'] ?? null, fn (Builder $q, string $d): Builder => $q->whereDate('created_at', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Ver')
                    ->iconButton()
                    ->tooltip('Ver detalle')
                    ->slideOver()
                    ->modalWidth('2xl')
                    ->infolist(fn (Infolist $infolist): Infolist => self::infolist($infolist)),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Actividad')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('created_at')->label('Fecha')->dateTime('d M Y · H:i:s'),
                    Infolists\Components\TextEntry::make('causer.name')->label('Usuario')->placeholder('— sistema —'),
                    Infolists\Components\TextEntry::make('event')->label('Acción')->badge(),
                    Infolists\Components\TextEntry::make('log_name')->label('Módulo')->badge()->color('gray'),
                    Infolists\Components\TextEntry::make('subject')
                        ->label('Registro')
                        ->getStateUsing(fn (Activity $record): string => class_basename((string) $record->subject_type).' #'.$record->subject_id),
                    Infolists\Components\TextEntry::make('propiedades')
                        ->label('Detalle de cambios')
                        ->getStateUsing(fn (Activity $record): string => json_encode($record->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '—')
                        ->extraAttributes(['data-mono' => true])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivities::route('/'),
        ];
    }
}
