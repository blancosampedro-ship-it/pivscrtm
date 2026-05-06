<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LvRutaDiaResource\Pages;
use App\Filament\Resources\LvRutaDiaResource\RelationManagers\ItemsRelationManager;
use App\Models\LvRutaDia;
use App\Models\Tecnico;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\ActionSize;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class LvRutaDiaResource extends Resource
{
    protected static ?string $model = LvRutaDia::class;

    protected static ?string $slug = 'rutas-dia';

    protected static ?string $navigationLabel = 'Rutas del día';

    protected static ?string $modelLabel = 'ruta del día';

    protected static ?string $pluralModelLabel = 'rutas del día';

    protected static ?string $navigationGroup = 'Planificación';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    public static function getNavigationBadge(): ?string
    {
        $today = CarbonImmutable::now('Europe/Madrid');
        $count = LvRutaDia::query()
            ->delDia($today)
            ->whereIn('status', [LvRutaDia::STATUS_PLANIFICADA, LvRutaDia::STATUS_EN_PROGRESO])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['tecnico:tecnico_id,nombre_completo,status', 'createdBy:id,name'])
            ->withCount([
                'items',
                'items as ambiguous_items_count' => fn (Builder $query): Builder => $query->whereHas(
                    'averiaIcca',
                    fn (Builder $averiaQuery): Builder => $averiaQuery->whereNull('piv_id')
                ),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Ruta')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('tecnico_id')
                        ->label('Técnico')
                        ->options(fn (): array => Tecnico::query()
                            ->where('status', 1)
                            ->orderBy('nombre_completo')
                            ->pluck('nombre_completo', 'tecnico_id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->rules([
                            fn (?LvRutaDia $record, Forms\Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $get): void {
                                $fecha = $record?->fecha?->format('Y-m-d') ?? (string) $get('fecha');

                                if ($fecha === '') {
                                    return;
                                }

                                $exists = LvRutaDia::query()
                                    ->where('tecnico_id', (int) $value)
                                    ->whereDate('fecha', $fecha)
                                    ->when($record !== null, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($exists) {
                                    $fail('Este técnico ya tiene una ruta para esa fecha.');
                                }
                            },
                        ])
                        ->disabled(fn (?LvRutaDia $record): bool => $record?->isEditable() === false),
                    Forms\Components\DatePicker::make('fecha')
                        ->label('Fecha')
                        ->required()
                        ->disabled(),
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options(self::statusOptions())
                        ->required()
                        ->disabled(fn (?LvRutaDia $record): bool => $record?->isEditable() === false),
                    Forms\Components\Textarea::make('notas_admin')
                        ->label('Notas admin')
                        ->maxLength(5000)
                        ->columnSpanFull()
                        ->disabled(fn (?LvRutaDia $record): bool => $record?->isEditable() === false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $now = CarbonImmutable::now('Europe/Madrid');

        return $table
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('fecha')->orderBy('tecnico_id'))
            ->columns([
                Tables\Columns\TextColumn::make('tecnico.nombre_completo')
                    ->label('Técnico')
                    ->getStateUsing(fn (LvRutaDia $record): string => $record->tecnico?->nombre_completo ?? '—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'tecnico',
                        fn (Builder $tecnicoQuery): Builder => $tecnicoQuery->where('nombre_completo', 'like', "%{$search}%")
                    ))
                    ->sortable(),
                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('Y-m-d')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state)),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Items')
                    ->badge()
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
                Tables\Columns\TextColumn::make('ambiguous_items_count')
                    ->label('Ambiguas')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Creada por')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('Y-m-d H:i')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tecnico_id')
                    ->label('Técnico')
                    ->options(fn (): array => Tecnico::query()->orderBy('nombre_completo')->pluck('nombre_completo', 'tecnico_id')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('status')
                    ->options(self::statusOptions()),
                Tables\Filters\Filter::make('fecha')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('fecha', '>=', $date))
                        ->when($data['hasta'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('fecha', '<=', $date))),
                Tables\Filters\Filter::make('mes')
                    ->form([
                        Forms\Components\Select::make('year')
                            ->label('Año')
                            ->options(fn (): array => collect(range($now->year - 1, $now->year + 1))->mapWithKeys(fn (int $year): array => [$year => (string) $year])->all())
                            ->default($now->year),
                        Forms\Components\Select::make('month')
                            ->label('Mes')
                            ->options(fn (): array => collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => str_pad((string) $month, 2, '0', STR_PAD_LEFT)])->all())
                            ->default($now->month),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['year'] ?? null, fn (Builder $query, int|string $year): Builder => $query->whereYear('fecha', (int) $year))
                        ->when($data['month'] ?? null, fn (Builder $query, int|string $month): Builder => $query->whereMonth('fecha', (int) $month))),
            ])
            ->recordAction('view')
            ->actionsPosition(ActionsPosition::AfterColumns)
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Ver')
                    ->icon('heroicon-m-eye')
                    ->size(ActionSize::Small)
                    ->color('gray')
                    ->slideOver()
                    ->modalWidth('4xl')
                    ->infolist(fn (Infolist $infolist): Infolist => self::infolist($infolist)),
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->size(ActionSize::Small),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('cancelar')
                    ->label('Cancelar rutas')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $records->each(fn (LvRutaDia $record): bool => $record->update(['status' => LvRutaDia::STATUS_CANCELADA]));
                    }),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Ruta del día')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('tecnico_nombre')
                        ->label('Técnico')
                        ->getStateUsing(fn (LvRutaDia $record): string => $record->tecnico?->nombre_completo ?? '—'),
                    Infolists\Components\TextEntry::make('fecha')
                        ->date('Y-m-d')
                        ->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                        ->color(fn (string $state): string => self::statusColor($state)),
                    Infolists\Components\TextEntry::make('notas_admin')
                        ->label('Notas admin')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
            Infolists\Components\Section::make('Items')
                ->schema([
                    Infolists\Components\TextEntry::make('items_resumen')
                        ->hiddenLabel()
                        ->getStateUsing(fn (LvRutaDia $record): string => $record->items()
                            ->with(['averiaIcca.piv:piv_id,parada_cod,municipio', 'revisionPendiente.piv:piv_id,parada_cod,municipio'])
                            ->get()
                            ->map(fn ($item): string => sprintf(
                                '%02d · %s · %s',
                                $item->orden,
                                self::tipoItemLabel($item->tipo_item),
                                ItemsRelationManager::panelLabel($item),
                            ))
                            ->join("\n"))
                        ->extraAttributes(['style' => 'white-space: pre-wrap;'])
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLvRutaDias::route('/'),
            'edit' => Pages\EditLvRutaDia::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            LvRutaDia::STATUS_PLANIFICADA => 'Planificada',
            LvRutaDia::STATUS_EN_PROGRESO => 'En progreso',
            LvRutaDia::STATUS_COMPLETADA => 'Completada',
            LvRutaDia::STATUS_CANCELADA => 'Cancelada',
        ];
    }

    public static function statusLabel(string $status): string
    {
        return self::statusOptions()[$status] ?? $status;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            LvRutaDia::STATUS_PLANIFICADA => 'primary',
            LvRutaDia::STATUS_EN_PROGRESO => 'warning',
            LvRutaDia::STATUS_COMPLETADA => 'success',
            LvRutaDia::STATUS_CANCELADA => 'gray',
            default => 'gray',
        };
    }

    public static function tipoItemLabel(string $tipo): string
    {
        return match ($tipo) {
            'correctivo' => 'Correctivo',
            'preventivo' => 'Preventivo',
            'carry_over' => 'Carry over',
            default => $tipo,
        };
    }
}
