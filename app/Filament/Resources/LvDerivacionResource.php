<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LvDerivacionResource\Pages;
use App\Filament\Resources\LvRutaDiaResource\RelationManagers\ItemsRelationManager;
use App\Models\LvDerivacion;
use App\Models\LvRutaDiaItem;
use App\Services\DerivacionService;
use DomainException;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\ActionSize;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class LvDerivacionResource extends Resource
{
    protected static ?string $model = LvDerivacion::class;

    protected static ?string $slug = 'derivaciones';

    protected static ?string $navigationLabel = 'Derivaciones';

    protected static ?string $modelLabel = 'derivación';

    protected static ?string $pluralModelLabel = 'derivaciones';

    protected static ?string $navigationGroup = 'Planificación';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    public static function getNavigationBadge(): ?string
    {
        $count = LvDerivacion::query()->abiertas()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'derivadoPor:id,name',
            'resueltoPor:id,name',
            'item.averiaIcca.piv:piv_id,parada_cod,municipio',
            'item.revisionPendiente.piv:piv_id,parada_cod,municipio',
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema(self::derivacionFormSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('fecha_derivacion', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('fecha_derivacion')
                    ->label('Fecha')
                    ->dateTime('Y-m-d H:i')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
                Tables\Columns\TextColumn::make('panel')
                    ->label('Panel')
                    ->state(fn (LvDerivacion $record): string => self::panelLabel($record->item))
                    ->extraAttributes(['data-mono' => true])
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'item.averiaIcca',
                        fn (Builder $averiaQuery): Builder => $averiaQuery->where('panel_id_sgip', 'like', "%{$search}%")
                    )->orWhereHas(
                        'item.averiaIcca.piv',
                        fn (Builder $pivQuery): Builder => $pivQuery->where('parada_cod', 'like', "%{$search}%")
                    )->orWhereHas(
                        'item.revisionPendiente.piv',
                        fn (Builder $pivQuery): Builder => $pivQuery->where('parada_cod', 'like', "%{$search}%")
                    )),
                Tables\Columns\TextColumn::make('tipo_causa')
                    ->label('Causa')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::causaLabel($state))
                    ->color('warning'),
                Tables\Columns\TextColumn::make('actor_responsable')
                    ->label('Actor')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::actorLabel($state))
                    ->color('primary'),
                Tables\Columns\TextColumn::make('actor_notas')
                    ->label('Notas actor')
                    ->limit(30)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state)),
                Tables\Columns\TextColumn::make('derivadoPor.name')
                    ->label('Derivado por')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions())
                    ->default('abiertas')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'abiertas', null => $query->whereIn('status', LvDerivacion::STATUSES_ABIERTAS),
                        'cerradas' => $query->whereIn('status', LvDerivacion::STATUSES_CERRADAS),
                        default => $query->where('status', $data['value']),
                    }),
                Tables\Filters\SelectFilter::make('tipo_causa')
                    ->label('Causa')
                    ->options(self::causaOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('tipo_causa', $data['value'])
                        : $query),
                Tables\Filters\SelectFilter::make('actor_responsable')
                    ->label('Actor')
                    ->options(self::actorOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('actor_responsable', $data['value'])
                        : $query),
                Tables\Filters\Filter::make('fecha_derivacion')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('fecha_derivacion', '>=', $date))
                        ->when($data['hasta'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('fecha_derivacion', '<=', $date))),
            ])
            ->headerActions([
                Tables\Actions\Action::make('nuevaDerivacion')
                    ->label('Nueva derivación')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form([
                        Forms\Components\Wizard::make([
                            Forms\Components\Wizard\Step::make('Item')
                                ->schema([
                                    self::itemSelect(),
                                ]),
                            Forms\Components\Wizard\Step::make('Derivación')
                                ->schema(self::derivacionFormSchema()),
                        ])->columnSpanFull(),
                    ])
                    ->action(function (array $data): void {
                        $item = LvRutaDiaItem::query()->findOrFail((int) $data['lv_ruta_dia_item_id']);
                        self::runAction(fn () => app(DerivacionService::class)->derivar($item, $data, auth()->user()));
                    }),
            ])
            ->recordAction('view')
            ->actionsPosition(ActionsPosition::AfterColumns)
            ->actions([
                Tables\Actions\Action::make('resolverExternamente')
                    ->label('Resolver externamente')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->size(ActionSize::Small)
                    ->visible(fn (LvDerivacion $record): bool => $record->isAbierta())
                    ->form([self::notasResolucionTextarea('Notas de resolución')])
                    ->action(fn (LvDerivacion $record, array $data): mixed => self::runAction(
                        fn () => app(DerivacionService::class)->resolverExternamente($record, $data, auth()->user())
                    )),
                Tables\Actions\Action::make('devolverARuta')
                    ->label('Devolver a ruta')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->size(ActionSize::Small)
                    ->visible(fn (LvDerivacion $record): bool => $record->isAbierta())
                    ->form([self::notasResolucionTextarea('Motivo')])
                    ->action(fn (LvDerivacion $record, array $data): mixed => self::runAction(
                        fn () => app(DerivacionService::class)->devolverARuta($record, $data, auth()->user())
                    )),
                Tables\Actions\Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->size(ActionSize::Small)
                    ->visible(fn (LvDerivacion $record): bool => $record->isAbierta())
                    ->form([self::notasResolucionTextarea('Motivo')])
                    ->requiresConfirmation()
                    ->action(fn (LvDerivacion $record, array $data): mixed => self::runAction(
                        fn () => app(DerivacionService::class)->cancelar($record, $data, auth()->user())
                    )),
                Tables\Actions\ViewAction::make()
                    ->label('Ver detalle')
                    ->icon('heroicon-m-eye')
                    ->size(ActionSize::Small)
                    ->color('gray')
                    ->slideOver()
                    ->modalWidth('4xl')
                    ->infolist(fn (Infolist $infolist): Infolist => self::infolist($infolist)),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Derivación')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('fecha_derivacion')->label('Fecha')->dateTime('Y-m-d H:i')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('status')->badge()->formatStateUsing(fn (string $state): string => self::statusLabel($state))->color(fn (string $state): string => self::statusColor($state)),
                    Infolists\Components\TextEntry::make('panel')->label('Panel')->getStateUsing(fn (LvDerivacion $record): string => self::panelLabel($record->item))->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('tipo_causa')->label('Causa')->badge()->formatStateUsing(fn (string $state): string => self::causaLabel($state))->color('warning'),
                    Infolists\Components\TextEntry::make('causa_otros_texto')->label('Texto otros')->placeholder('—'),
                    Infolists\Components\TextEntry::make('actor_responsable')->label('Actor')->badge()->formatStateUsing(fn (string $state): string => self::actorLabel($state))->color('primary'),
                    Infolists\Components\TextEntry::make('actor_notas')->label('Notas actor')->placeholder('—')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('notas_derivacion')->label('Notas derivación')->placeholder('—')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('derivadoPor.name')->label('Derivado por')->placeholder('—'),
                    Infolists\Components\TextEntry::make('resueltoPor.name')->label('Resuelto por')->placeholder('—'),
                    Infolists\Components\TextEntry::make('fecha_resolucion')->label('Fecha resolución')->dateTime('Y-m-d H:i')->placeholder('—')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('resuelto_notas')->label('Notas cierre')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLvDerivaciones::route('/'),
        ];
    }

    /** @return array<int, Forms\Components\Component> */
    public static function derivacionFormSchema(): array
    {
        return [
            Forms\Components\Select::make('tipo_causa')
                ->label('Causa')
                ->options(self::causaOptions())
                ->required()
                ->live(),
            Forms\Components\Textarea::make('causa_otros_texto')
                ->label('Texto otros')
                ->maxLength(500)
                ->visible(fn (Forms\Get $get): bool => $get('tipo_causa') === LvDerivacion::CAUSA_OTROS)
                ->required(fn (Forms\Get $get): bool => $get('tipo_causa') === LvDerivacion::CAUSA_OTROS)
                ->columnSpanFull(),
            Forms\Components\Select::make('actor_responsable')
                ->label('Actor responsable')
                ->options(self::actorOptions())
                ->required(),
            Forms\Components\TextInput::make('actor_notas')
                ->label('Notas actor')
                ->maxLength(200),
            Forms\Components\Textarea::make('notas_derivacion')
                ->label('Notas derivación')
                ->columnSpanFull(),
        ];
    }

    public static function panelLabel(?LvRutaDiaItem $item): string
    {
        if ($item === null) {
            return '—';
        }

        return ItemsRelationManager::panelLabel($item);
    }

    /** @return array<string, string> */
    public static function causaOptions(): array
    {
        return [
            LvDerivacion::CAUSA_SIN_TENSION => 'Sin tensión / alimentación externa',
            LvDerivacion::CAUSA_PANEL_OFFLINE => 'Panel offline / sin comunicaciones',
            LvDerivacion::CAUSA_INCIDENCIA_SOFTWARE => 'Incidencia software / plataforma',
            LvDerivacion::CAUSA_VANDALISMO => 'Vandalismo / daño físico',
            LvDerivacion::CAUSA_PANEL_INACCESIBLE => 'Panel inaccesible',
            LvDerivacion::CAUSA_MATERIAL => 'Material no disponible',
            LvDerivacion::CAUSA_AUTORIZACION => 'Requiere autorización / permiso previo',
            LvDerivacion::CAUSA_TERCERO => 'Requiere apoyo de tercero',
            LvDerivacion::CAUSA_OTROS => 'Otros',
        ];
    }

    public static function causaLabel(string $causa): string
    {
        $options = self::causaOptions();

        return $options[$causa] ?? $causa;
    }

    /** @return array<string, string> */
    public static function actorOptions(): array
    {
        return [
            LvDerivacion::ACTOR_CLEAR_CHANNEL => 'Clear Channel',
            LvDerivacion::ACTOR_INDUSTRIAL => 'Industrial',
            LvDerivacion::ACTOR_CRTM => 'CRTM',
            LvDerivacion::ACTOR_AYUNTAMIENTO => 'Ayuntamiento',
            LvDerivacion::ACTOR_OPERADOR_SIM => 'Operador SIM',
            LvDerivacion::ACTOR_PROVEEDOR => 'Proveedor',
            LvDerivacion::ACTOR_INTERNO_WINFIN => 'Interno Winfin',
            LvDerivacion::ACTOR_OTROS => 'Otros',
        ];
    }

    public static function actorLabel(string $actor): string
    {
        $options = self::actorOptions();

        return $options[$actor] ?? $actor;
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            'abiertas' => 'Abiertas',
            'cerradas' => 'Cerradas',
            LvDerivacion::STATUS_PENDIENTE_TERCERO => 'Pendiente tercero',
            LvDerivacion::STATUS_EN_CURSO => 'En curso',
            LvDerivacion::STATUS_RESUELTO_EXTERNO => 'Resuelto externo',
            LvDerivacion::STATUS_DEVUELTO_A_RUTA => 'Devuelto a ruta',
            LvDerivacion::STATUS_CANCELADA => 'Cancelada',
        ];
    }

    public static function statusLabel(string $status): string
    {
        $options = self::statusOptions();

        return $options[$status] ?? $status;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            LvDerivacion::STATUS_PENDIENTE_TERCERO => 'warning',
            LvDerivacion::STATUS_EN_CURSO => 'info',
            LvDerivacion::STATUS_RESUELTO_EXTERNO => 'success',
            LvDerivacion::STATUS_DEVUELTO_A_RUTA => 'gray',
            LvDerivacion::STATUS_CANCELADA => 'danger',
            default => 'gray',
        };
    }

    private static function itemSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('lv_ruta_dia_item_id')
            ->label('Item')
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => self::itemOptions($search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => self::itemOptionLabel((int) $value))
            ->required();
    }

    /** @return array<int, string> */
    private static function itemOptions(string $search = ''): array
    {
        return LvRutaDiaItem::query()
            ->with(['rutaDia:id,fecha,tecnico_id', 'averiaIcca.piv:piv_id,parada_cod', 'revisionPendiente.piv:piv_id,parada_cod', 'derivacionAbierta'])
            ->whereIn('status', [LvRutaDiaItem::STATUS_PENDIENTE, LvRutaDiaItem::STATUS_CERRADO])
            ->whereDoesntHave('derivacionAbierta')
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->whereHas('averiaIcca', fn (Builder $averiaQuery): Builder => $averiaQuery->where('panel_id_sgip', 'like', "%{$search}%"))
                    ->orWhereHas('averiaIcca.piv', fn (Builder $pivQuery): Builder => $pivQuery->where('parada_cod', 'like', "%{$search}%"))
                    ->orWhereHas('revisionPendiente.piv', fn (Builder $pivQuery): Builder => $pivQuery->where('parada_cod', 'like', "%{$search}%"));
            }))
            ->latest('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (LvRutaDiaItem $item): array => [$item->id => self::itemOptionLabel($item->id, $item)])
            ->all();
    }

    private static function itemOptionLabel(int $id, ?LvRutaDiaItem $item = null): ?string
    {
        $item ??= LvRutaDiaItem::query()
            ->with(['rutaDia:id,fecha,tecnico_id', 'averiaIcca.piv:piv_id,parada_cod', 'revisionPendiente.piv:piv_id,parada_cod'])
            ->find($id);

        if ($item === null) {
            return null;
        }

        return sprintf('#%d · %s · %s · %s', $item->id, strtoupper((string) $item->tipo_item), self::panelLabel($item), $item->rutaDia?->fecha?->format('Y-m-d') ?? 'sin fecha');
    }

    private static function notasResolucionTextarea(string $label): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make('notas')
            ->label($label)
            ->required()
            ->columnSpanFull();
    }

    private static function runAction(callable $callback): mixed
    {
        try {
            $result = $callback();

            Notification::make()->title('Derivación actualizada')->success()->send();

            return $result;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return null;
        }
    }
}
