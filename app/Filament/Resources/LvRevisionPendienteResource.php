<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LvRevisionPendienteResource\Pages;
use App\Models\LvRevisionPendiente;
use App\Models\PivZona;
use App\Services\RevisionPendientePromotorService;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class LvRevisionPendienteResource extends Resource
{
    protected static ?string $model = LvRevisionPendiente::class;

    protected static ?string $slug = 'revisiones-pendientes';

    protected static ?string $navigationLabel = 'Decisiones del día';

    protected static ?string $modelLabel = 'revisión pendiente';

    protected static ?string $pluralModelLabel = 'revisiones pendientes';

    protected static ?string $navigationGroup = 'Planificación';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    /** @var array<int, string>|null */
    private static ?array $zonaNombrePorMunicipio = null;

    public static function getNavigationBadge(): ?string
    {
        $today = CarbonImmutable::now('Europe/Madrid')->startOfDay();
        $count = LvRevisionPendiente::query()
            ->where('status', LvRevisionPendiente::STATUS_PENDIENTE)
            ->where('periodo_year', $today->year)
            ->where('periodo_month', $today->month)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'piv:piv_id,parada_cod,municipio',
            'decisionUser:id,name',
            'carryOverOrigen:id,periodo_year,periodo_month,status',
        ]);
    }

    public static function table(Table $table): Table
    {
        $now = CarbonImmutable::now('Europe/Madrid');

        return $table
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('carry_over_origen_id IS NULL ASC')
                ->orderByRaw("CASE status WHEN 'pendiente' THEN 1 WHEN 'requiere_visita' THEN 2 WHEN 'excepcion' THEN 3 WHEN 'verificada_remoto' THEN 4 WHEN 'completada' THEN 5 ELSE 6 END ASC")
                ->orderBy('piv_id'))
            ->columns([
                Tables\Columns\TextColumn::make('carry_badge')
                    ->label('')
                    ->state(fn (LvRevisionPendiente $record): ?string => $record->isCarryOver() ? 'Carry' : null)
                    ->badge()
                    ->color('warning')
                    ->tooltip(fn (LvRevisionPendiente $record): ?string => $record->isCarryOver()
                        ? sprintf(
                            'Pendiente desde periodo %s-%s (status: %s)',
                            $record->carryOverOrigen?->periodo_year ?? '—',
                            $record->carryOverOrigen?->periodo_month ?? '—',
                            $record->carryOverOrigen?->status ?? '—',
                        )
                        : null),
                Tables\Columns\TextColumn::make('piv.parada_cod')
                    ->label('Panel')
                    ->getStateUsing(fn (LvRevisionPendiente $record): string => $record->piv?->parada_cod ? mb_strtoupper(trim((string) $record->piv->parada_cod)) : '—')
                    ->description(fn (LvRevisionPendiente $record): string => 'ID '.$record->piv_id)
                    ->extraAttributes(['data-mono' => true])
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'piv',
                        fn (Builder $pivQuery): Builder => $pivQuery->where('parada_cod', 'like', "%{$search}%")
                    )),
                Tables\Columns\TextColumn::make('zona')
                    ->label('Zona')
                    ->getStateUsing(fn (LvRevisionPendiente $record): string => self::zonaNombre($record)),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        LvRevisionPendiente::STATUS_PENDIENTE => 'gray',
                        LvRevisionPendiente::STATUS_VERIFICADA_REMOTO => 'success',
                        LvRevisionPendiente::STATUS_REQUIERE_VISITA => 'warning',
                        LvRevisionPendiente::STATUS_EXCEPCION => 'danger',
                        LvRevisionPendiente::STATUS_COMPLETADA => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('fecha_planificada')
                    ->label('Fecha')
                    ->date('Y-m-d')
                    ->extraAttributes(['data-mono' => true])
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('decision_user')
                    ->label('Decidido por')
                    ->getStateUsing(fn (LvRevisionPendiente $record): string => $record->decisionUser?->name ?? '—'),
                Tables\Columns\TextColumn::make('decision_notas')
                    ->label('Notas')
                    ->limit(40)
                    ->tooltip(fn (LvRevisionPendiente $record): ?string => $record->decision_notas),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('zona')
                    ->label('Zona')
                    ->options(fn (): array => PivZona::query()->orderBy('sort_order')->pluck('nombre', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereExists(function ($subquery) use ($data): void {
                            $subquery->select(DB::raw(1))
                                ->from('lv_piv_zona_municipio as zona_municipio')
                                ->join('piv', 'piv.municipio', '=', DB::raw('zona_municipio.municipio_modulo_id'))
                                ->whereColumn('piv.piv_id', 'lv_revision_pendiente.piv_id')
                                ->where('zona_municipio.zona_id', $data['value']);
                        });
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->options(self::statusOptions()),
                Tables\Filters\TernaryFilter::make('carry_over')
                    ->label('Carry-over')
                    ->placeholder('Todos')
                    ->trueLabel('Solo carry overs')
                    ->falseLabel('Sin carry')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('carry_over_origen_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('carry_over_origen_id'),
                    ),
                Tables\Filters\Filter::make('fecha_planificada')
                    ->form([
                        Forms\Components\DatePicker::make('fecha_planificada')
                            ->label('Fecha planificada'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['fecha_planificada'] ?? null)
                        ? $query->whereDate('fecha_planificada', $data['fecha_planificada'])
                        : $query),
                Tables\Filters\Filter::make('solo_hoy')
                    ->label('Solo hoy + carry overs')
                    ->default()
                    ->query(function (Builder $query): Builder {
                        $today = CarbonImmutable::now('Europe/Madrid')->toDateString();

                        return $query->where(function (Builder $query) use ($today): void {
                            $query->whereDate('fecha_planificada', $today)
                                ->orWhere(function (Builder $query): void {
                                    $query->whereNotNull('carry_over_origen_id')
                                        ->where('status', LvRevisionPendiente::STATUS_PENDIENTE);
                                });
                        });
                    }),
                Tables\Filters\Filter::make('mes')
                    ->form([
                        Forms\Components\Select::make('periodo_year')
                            ->label('Año')
                            ->options(self::yearOptions())
                            ->default($now->year),
                        Forms\Components\Select::make('periodo_month')
                            ->label('Mes')
                            ->options(self::monthOptions())
                            ->default($now->month),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['periodo_year'] ?? null, fn (Builder $query, int|string $year): Builder => $query->where('periodo_year', (int) $year))
                        ->when($data['periodo_month'] ?? null, fn (Builder $query, int|string $month): Builder => $query->where('periodo_month', (int) $month))),
            ])
            ->actions([
                Tables\Actions\Action::make('verificarRemoto')
                    ->label('Verificar remoto')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (LvRevisionPendiente $record): bool => $record->status === LvRevisionPendiente::STATUS_PENDIENTE)
                    ->form([
                        Forms\Components\Textarea::make('decision_notas')
                            ->label('Notas')
                            ->maxLength(2000),
                    ])
                    ->action(function (LvRevisionPendiente $record, array $data): void {
                        $record->update([
                            'status' => LvRevisionPendiente::STATUS_VERIFICADA_REMOTO,
                            'decision_user_id' => auth()->id(),
                            'decision_at' => now(),
                            'decision_notas' => $data['decision_notas'] ?? null,
                        ]);

                        Notification::make()->title('Marcada como verificada remoto')->success()->send();
                    }),
                Tables\Actions\Action::make('requiereVisita')
                    ->label('Requiere visita')
                    ->icon('heroicon-o-truck')
                    ->color('warning')
                    ->visible(fn (LvRevisionPendiente $record): bool => $record->status === LvRevisionPendiente::STATUS_PENDIENTE)
                    ->form([
                        Forms\Components\DatePicker::make('fecha_planificada')
                            ->label('Fecha de visita')
                            ->required()
                            ->minDate(today('Europe/Madrid'))
                            ->default(today('Europe/Madrid')),
                        Forms\Components\Textarea::make('decision_notas')
                            ->label('Notas')
                            ->maxLength(2000),
                    ])
                    ->action(function (LvRevisionPendiente $record, array $data): void {
                        $record->update([
                            'status' => LvRevisionPendiente::STATUS_REQUIERE_VISITA,
                            'fecha_planificada' => $data['fecha_planificada'],
                            'decision_user_id' => auth()->id(),
                            'decision_at' => now(),
                            'decision_notas' => $data['decision_notas'] ?? null,
                        ]);

                        Notification::make()->title('Programada para visita')->success()->send();
                    }),
                Tables\Actions\Action::make('marcarExcepcion')
                    ->label('Excepción')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (LvRevisionPendiente $record): bool => $record->status === LvRevisionPendiente::STATUS_PENDIENTE)
                    ->form([
                        Forms\Components\Textarea::make('decision_notas')
                            ->label('Motivo de la excepción')
                            ->required()
                            ->maxLength(2000),
                    ])
                    ->action(function (LvRevisionPendiente $record, array $data): void {
                        $record->update([
                            'status' => LvRevisionPendiente::STATUS_EXCEPCION,
                            'decision_user_id' => auth()->id(),
                            'decision_at' => now(),
                            'decision_notas' => $data['decision_notas'],
                        ]);

                        Notification::make()->title('Marcada como excepción')->warning()->send();
                    }),
                Tables\Actions\Action::make('revertir')
                    ->label('Revertir decisión')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (LvRevisionPendiente $record): bool => ! in_array($record->status, [
                        LvRevisionPendiente::STATUS_PENDIENTE,
                        LvRevisionPendiente::STATUS_COMPLETADA,
                    ], true) && $record->asignacion_id === null)
                    ->requiresConfirmation()
                    ->action(function (LvRevisionPendiente $record): void {
                        $record->update([
                            'status' => LvRevisionPendiente::STATUS_PENDIENTE,
                            'fecha_planificada' => null,
                            'decision_user_id' => null,
                            'decision_at' => null,
                            'decision_notas' => null,
                        ]);

                        Notification::make()->title('Decisión revertida')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('verificarRemotoBulk')
                    ->label('Verificar remoto')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('decision_notas')
                            ->label('Notas')
                            ->maxLength(2000),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $userId = auth()->id();
                        $now = now();
                        $count = 0;

                        DB::transaction(function () use ($records, $data, $userId, $now, &$count): void {
                            foreach ($records as $record) {
                                if ($record->status !== LvRevisionPendiente::STATUS_PENDIENTE) {
                                    continue;
                                }

                                $record->update([
                                    'status' => LvRevisionPendiente::STATUS_VERIFICADA_REMOTO,
                                    'decision_user_id' => $userId,
                                    'decision_at' => $now,
                                    'decision_notas' => $data['decision_notas'] ?? null,
                                ]);
                                $count++;
                            }
                        });

                        Notification::make()->title("{$count} marcadas verificadas remoto")->success()->send();
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('promoverAhora')
                    ->label('Promover ahora')
                    ->icon('heroicon-o-bolt')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Promueve a asignación legacy las filas con status requiere_visita y fecha de hoy.')
                    ->action(function (): void {
                        $result = app(RevisionPendientePromotorService::class)->promoverDelDia();

                        Notification::make()
                            ->title($result['promoted'].' promocionadas a asignación')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLvRevisionPendientes::route('/'),
        ];
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return [
            LvRevisionPendiente::STATUS_PENDIENTE => 'Pendiente',
            LvRevisionPendiente::STATUS_VERIFICADA_REMOTO => 'Verificada remoto',
            LvRevisionPendiente::STATUS_REQUIERE_VISITA => 'Requiere visita',
            LvRevisionPendiente::STATUS_EXCEPCION => 'Excepción',
            LvRevisionPendiente::STATUS_COMPLETADA => 'Completada',
        ];
    }

    private static function statusLabel(string $status): string
    {
        return self::statusOptions()[$status] ?? $status;
    }

    /** @return array<int, string> */
    private static function yearOptions(): array
    {
        $current = (int) now('Europe/Madrid')->year;
        $years = range($current - 1, $current + 1);

        return array_combine($years, array_map('strval', $years));
    }

    /** @return array<int, string> */
    private static function monthOptions(): array
    {
        return [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];
    }

    private static function zonaNombre(LvRevisionPendiente $record): string
    {
        $municipioId = (int) ($record->piv?->municipio ?? 0);

        if ($municipioId === 0) {
            return '—';
        }

        return self::zonaNombrePorMunicipio()[$municipioId] ?? '—';
    }

    /** @return array<int, string> */
    private static function zonaNombrePorMunicipio(): array
    {
        if (self::$zonaNombrePorMunicipio !== null) {
            return self::$zonaNombrePorMunicipio;
        }

        self::$zonaNombrePorMunicipio = DB::table('lv_piv_zona_municipio')
            ->join('lv_piv_zona', 'lv_piv_zona.id', '=', 'lv_piv_zona_municipio.zona_id')
            ->pluck('lv_piv_zona.nombre', 'lv_piv_zona_municipio.municipio_modulo_id')
            ->mapWithKeys(fn (string $nombre, int|string $municipioId): array => [(int) $municipioId => $nombre])
            ->all();

        return self::$zonaNombrePorMunicipio;
    }
}
