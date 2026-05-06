<?php

declare(strict_types=1);

namespace App\Filament\Resources\LvRutaDiaResource\RelationManagers;

use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Services\PlanificadorDelDiaService;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items de la ruta';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('orden')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'averiaIcca.piv:piv_id,parada_cod,municipio',
                'revisionPendiente.piv:piv_id,parada_cod,municipio',
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
                    ->state(fn (LvRutaDiaItem $record): string => self::municipioId($record) !== null ? (string) self::municipioId($record) : '—')
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('km')
                    ->label('Km')
                    ->state(fn (LvRutaDiaItem $record): string => self::kmDesdeCiempozuelos($record) !== null ? self::kmDesdeCiempozuelos($record).' km' : '—')
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
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
                Tables\Actions\DeleteAction::make()
                    ->label('Eliminar')
                    ->visible(fn (): bool => $this->ownerRecord instanceof LvRutaDia && $this->ownerRecord->isEditable()),
            ]);
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

        $km = DB::table('lv_piv_ruta_municipio')
            ->where('municipio_modulo_id', $municipioId)
            ->value('km_desde_ciempozuelos');

        return $km !== null ? (int) $km : null;
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
            default => 'gray',
        };
    }
}
