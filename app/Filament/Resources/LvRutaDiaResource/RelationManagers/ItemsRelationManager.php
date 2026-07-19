<?php

declare(strict_types=1);

namespace App\Filament\Resources\LvRutaDiaResource\RelationManagers;

use App\Filament\Resources\LvDerivacionResource;
use App\Filament\Resources\PivResource;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\Modulo;
use App\Services\DerivacionService;
use App\Services\PlanificadorDelDiaService;
use DomainException;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\ActionSize;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items de la ruta';

    /**
     * Cache estático municipio_modulo_id → {nombre, km, ruta_codigo, ruta_nombre}.
     * 1 query precargada por request, evita N+1 con N items.
     *
     * @var array<int, array{nombre: ?string, km: ?int, ruta_codigo: ?string, ruta_nombre: ?string}>|null
     */
    private static ?array $municipioMap = null;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('orden')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'averiaIcca.piv:piv_id,parada_cod,municipio',
                'revisionPendiente.piv:piv_id,parada_cod,municipio',
                'derivacionAbierta',
            ]))
            ->reorderable('orden')
            ->defaultSort('orden')
            ->columns([
                Tables\Columns\TextColumn::make('orden')
                    ->label('Orden')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
                Tables\Columns\TextColumn::make('tipo_item')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::tipoLabel($state))
                    ->color(fn (string $state): string => self::tipoColor($state)),
                Tables\Columns\TextColumn::make('panel')
                    ->label('Panel')
                    ->state(fn (LvRutaDiaItem $record): string => self::panelLabel($record))
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('municipio')
                    ->label('Municipio')
                    ->state(fn (LvRutaDiaItem $record): string => self::municipioNombre($record) ?? '—'),
                Tables\Columns\TextColumn::make('ruta')
                    ->label('Ruta')
                    ->state(fn (LvRutaDiaItem $record): string => self::rutaCodigo($record) ?? 'Sin ruta')
                    ->badge()
                    ->color(fn (LvRutaDiaItem $record): string => self::rutaCodigo($record) !== null ? 'primary' : 'gray')
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('km')
                    ->label('Km')
                    ->state(fn (LvRutaDiaItem $record): string => self::kmDesdeCiempozuelos($record) !== null ? self::kmDesdeCiempozuelos($record).' km' : '—')
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state)),
                Tables\Columns\TextColumn::make('ambigua')
                    ->label('')
                    ->state(fn (LvRutaDiaItem $record): ?string => self::isAmbiguous($record) ? 'Ambigua' : null)
                    ->badge()
                    ->color('warning'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('anadirDesdePropuesta')
                    ->label('Añadir desde propuesta')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->visible(fn (): bool => $this->ownerRecord instanceof LvRutaDia && $this->ownerRecord->isEditable())
                    ->form([
                        Forms\Components\Select::make('item_key')
                            ->label('Item propuesto')
                            ->options(fn (): array => $this->availableItemOptions())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $this->addItemFromProposal((string) $data['item_key']);
                    }),
            ])
            ->actionsPosition(ActionsPosition::AfterColumns)
            ->actions([
                Tables\Actions\Action::make('derivar')
                    ->label('Derivar')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('warning')
                    ->size(ActionSize::Small)
                    ->visible(fn (LvRutaDiaItem $record): bool => in_array($record->status, [LvRutaDiaItem::STATUS_PENDIENTE, LvRutaDiaItem::STATUS_CERRADO], true)
                        && ! $record->tieneDerivacionAbierta())
                    ->form(LvDerivacionResource::derivacionFormSchema())
                    ->action(function (LvRutaDiaItem $record, array $data): void {
                        try {
                            app(DerivacionService::class)->derivar($record, $data, auth()->user());

                            Notification::make()->title('Item derivado')->success()->send();
                        } catch (DomainException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('verPanel')
                    ->label('Ver panel')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->size(ActionSize::Small)
                    ->url(fn (LvRutaDiaItem $record): ?string => self::pivIdResolved($record) !== null
                        ? PivResource::getUrl('view', ['record' => self::pivIdResolved($record)])
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (LvRutaDiaItem $record): bool => self::pivIdResolved($record) !== null),
                Tables\Actions\DeleteAction::make()
                    ->label('Eliminar')
                    ->visible(fn (): bool => $this->ownerRecord instanceof LvRutaDia && $this->ownerRecord->isEditable()),
            ]);
    }

    public static function municipioNombre(LvRutaDiaItem $item): ?string
    {
        $municipioId = self::municipioId($item);
        if ($municipioId === null) {
            return null;
        }

        return self::getMunicipioMap()[$municipioId]['nombre'] ?? null;
    }

    public static function rutaCodigo(LvRutaDiaItem $item): ?string
    {
        $municipioId = self::municipioId($item);
        if ($municipioId === null) {
            return null;
        }

        return self::getMunicipioMap()[$municipioId]['ruta_codigo'] ?? null;
    }

    public static function pivIdResolved(LvRutaDiaItem $item): ?int
    {
        if ($item->tipo_item === LvRutaDiaItem::TIPO_CORRECTIVO) {
            return $item->averiaIcca?->piv_id;
        }

        return $item->revisionPendiente?->piv_id;
    }

    public static function panelLabel(LvRutaDiaItem $item): string
    {
        if ($item->tipo_item === LvRutaDiaItem::TIPO_CORRECTIVO) {
            return $item->averiaIcca?->piv?->parada_cod
                ? trim((string) $item->averiaIcca->piv->parada_cod)
                : ($item->averiaIcca?->panel_id_sgip ?? '—');
        }

        return $item->revisionPendiente?->piv?->parada_cod
            ? trim((string) $item->revisionPendiente->piv->parada_cod)
            : '—';
    }

    public static function municipioId(LvRutaDiaItem $item): ?int
    {
        $municipio = $item->tipo_item === LvRutaDiaItem::TIPO_CORRECTIVO
            ? $item->averiaIcca?->piv?->municipio
            : $item->revisionPendiente?->piv?->municipio;

        return $municipio !== null ? (int) $municipio : null;
    }

    public static function kmDesdeCiempozuelos(LvRutaDiaItem $item): ?int
    {
        $municipioId = self::municipioId($item);

        if ($municipioId === null) {
            return null;
        }

        return self::getMunicipioMap()[$municipioId]['km'] ?? null;
    }

    /**
     * Cache estático municipio_modulo_id → {nombre, km, ruta_codigo, ruta_nombre}.
     * 2 queries precargadas por request (modulo via Eloquent para aplicar cast
     * Latin1String + ruta_municipio via DB::table). Evita N+1.
     *
     * @return array<int, array{nombre: ?string, km: ?int, ruta_codigo: ?string, ruta_nombre: ?string}>
     */
    private static function getMunicipioMap(): array
    {
        if (self::$municipioMap !== null) {
            return self::$municipioMap;
        }

        // Eloquent con cast Latin1String aplica decoding UTF-8 correcto a modulo.nombre.
        $modulos = Modulo::municipios()->get(['modulo_id', 'nombre']);

        // Lookup ruta + km por municipio_modulo_id (UTF-8 nativo de lv_piv_ruta*).
        $rutaInfo = DB::table('lv_piv_ruta_municipio as rm')
            ->leftJoin('lv_piv_ruta as r', 'r.id', '=', 'rm.ruta_id')
            ->select('rm.municipio_modulo_id', 'rm.km_desde_ciempozuelos as km', 'r.codigo as ruta_codigo', 'r.nombre as ruta_nombre')
            ->get()
            ->keyBy('municipio_modulo_id');

        self::$municipioMap = $modulos->mapWithKeys(function (Modulo $m) use ($rutaInfo): array {
            $info = $rutaInfo->get($m->modulo_id);

            return [(int) $m->modulo_id => [
                'nombre' => $m->nombre !== null ? trim((string) $m->nombre) : null,
                'km' => $info?->km !== null ? (int) $info->km : null,
                'ruta_codigo' => $info?->ruta_codigo,
                'ruta_nombre' => $info?->ruta_nombre,
            ]];
        })->all();

        return self::$municipioMap;
    }

    private static function isAmbiguous(LvRutaDiaItem $item): bool
    {
        return $item->tipo_item === LvRutaDiaItem::TIPO_CORRECTIVO && $item->averiaIcca?->piv_id === null;
    }

    /** @return array<string, string> */
    private function availableItemOptions(): array
    {
        if (! $this->ownerRecord instanceof LvRutaDia) {
            return [];
        }

        $existing = $this->ownerRecord->items()
            ->get(['tipo_item', 'lv_averia_icca_id', 'lv_revision_pendiente_id'])
            ->map(fn (LvRutaDiaItem $item): string => $item->tipo_item === LvRutaDiaItem::TIPO_CORRECTIVO
                ? LvRutaDiaItem::TIPO_CORRECTIVO.':'.$item->lv_averia_icca_id
                : $item->tipo_item.':'.$item->lv_revision_pendiente_id)
            ->all();

        $resultado = app(PlanificadorDelDiaService::class)->computar($this->ownerRecord->fecha);
        $options = [];

        foreach ($resultado['grupos'] as $grupo) {
            foreach ($grupo['items'] as $item) {
                $key = $item['tipo'].':'.$item['lv_id'];

                if (in_array($key, $existing, true)) {
                    continue;
                }

                $panel = $item['parada_cod'] ?? $item['panel_id_sgip'] ?? '—';
                $km = $item['km_desde_ciempozuelos'] !== null ? $item['km_desde_ciempozuelos'].' km' : '—';
                $options[$key] = sprintf('%s · %s · %s · %s', self::tipoLabel((string) $item['tipo']), $grupo['ruta_codigo'], $panel, $km);
            }
        }

        return $options;
    }

    private function addItemFromProposal(string $itemKey): void
    {
        if (! $this->ownerRecord instanceof LvRutaDia || ! $this->ownerRecord->isEditable()) {
            return;
        }

        [$tipo, $id] = explode(':', $itemKey, 2);
        $nextOrder = ((int) $this->ownerRecord->items()->max('orden')) + 1;

        LvRutaDiaItem::create([
            'ruta_dia_id' => $this->ownerRecord->id,
            'orden' => $nextOrder,
            'tipo_item' => $tipo,
            'lv_averia_icca_id' => $tipo === LvRutaDiaItem::TIPO_CORRECTIVO ? (int) $id : null,
            'lv_revision_pendiente_id' => $tipo !== LvRutaDiaItem::TIPO_CORRECTIVO ? (int) $id : null,
            'status' => LvRutaDiaItem::STATUS_PENDIENTE,
        ]);
    }

    private static function tipoLabel(string $tipo): string
    {
        return match ($tipo) {
            LvRutaDiaItem::TIPO_CORRECTIVO => 'Correctivo',
            LvRutaDiaItem::TIPO_PREVENTIVO => 'Preventivo',
            LvRutaDiaItem::TIPO_CARRY_OVER => 'Carry over',
            default => $tipo,
        };
    }

    private static function tipoColor(string $tipo): string
    {
        return match ($tipo) {
            LvRutaDiaItem::TIPO_CORRECTIVO => 'danger',
            LvRutaDiaItem::TIPO_PREVENTIVO => 'primary',
            LvRutaDiaItem::TIPO_CARRY_OVER => 'warning',
            default => 'gray',
        };
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            LvRutaDiaItem::STATUS_PENDIENTE => 'Pendiente',
            LvRutaDiaItem::STATUS_EN_PROGRESO => 'En progreso',
            LvRutaDiaItem::STATUS_CERRADO => 'Cerrado',
            LvRutaDiaItem::STATUS_NO_RESUELTO => 'No resuelto',
            LvRutaDiaItem::STATUS_DERIVADO => 'Derivado',
            default => $status,
        };
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            LvRutaDiaItem::STATUS_PENDIENTE => 'gray',
            LvRutaDiaItem::STATUS_EN_PROGRESO => 'warning',
            LvRutaDiaItem::STATUS_CERRADO => 'success',
            LvRutaDiaItem::STATUS_NO_RESUELTO => 'danger',
            LvRutaDiaItem::STATUS_DERIVADO => 'warning',
            default => 'gray',
        };
    }
}
