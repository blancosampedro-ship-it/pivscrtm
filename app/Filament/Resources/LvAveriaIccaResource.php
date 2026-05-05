<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LvAveriaIccaResource\Pages;
use App\Models\LvAveriaIcca;
use App\Models\PivRuta;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class LvAveriaIccaResource extends Resource
{
    protected static ?string $model = LvAveriaIcca::class;

    protected static ?string $slug = 'averias-icca';

    protected static ?string $navigationLabel = 'Averías ICCA';

    protected static ?string $modelLabel = 'avería ICCA';

    protected static ?string $pluralModelLabel = 'averías ICCA';

    protected static ?string $navigationGroup = 'Averías';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    public static function getNavigationBadge(): ?string
    {
        $count = LvAveriaIcca::query()->activas()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'piv:piv_id,parada_cod,municipio',
            'importedBy:id,name',
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('activa')
                ->orderByDesc('fecha_import'))
            ->columns([
                Tables\Columns\TextColumn::make('sgip_id')
                    ->label('SGIP')
                    ->badge()
                    ->extraAttributes(['data-mono' => true])
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('panel_id_sgip')
                    ->label('Panel SGIP')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('piv.parada_cod')
                    ->label('PIV')
                    ->getStateUsing(fn (LvAveriaIcca $record): string => $record->piv?->parada_cod ? mb_strtoupper(trim((string) $record->piv->parada_cod)) : '—')
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('categoria')
                    ->label('Categoría')
                    ->badge()
                    ->color(fn (string $state): string => self::categoriaColor($state)),
                Tables\Columns\TextColumn::make('descripcion')
                    ->label('Descripción')
                    ->limit(60)
                    ->wrap(),
                Tables\Columns\IconColumn::make('activa')
                    ->label('Activa')
                    ->boolean(),
                Tables\Columns\TextColumn::make('fecha_import')
                    ->label('Import')
                    ->dateTime('Y-m-d H:i')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
                Tables\Columns\TextColumn::make('importedBy.name')
                    ->label('Importado por')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('categoria')
                    ->options(self::categoriaOptions()),
                Tables\Filters\TernaryFilter::make('activa')
                    ->label('Activa')
                    ->placeholder('Todas')
                    ->trueLabel('Solo activas')
                    ->falseLabel('Solo inactivas'),
                Tables\Filters\Filter::make('fecha_import')
                    ->form([
                        Forms\Components\DatePicker::make('desde'),
                        Forms\Components\DatePicker::make('hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('fecha_import', '>=', $date))
                        ->when($data['hasta'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('fecha_import', '<=', $date))),
                Tables\Filters\SelectFilter::make('ruta')
                    ->label('Ruta')
                    ->options(fn (): array => PivRuta::query()->orderBy('sort_order')->pluck('nombre', 'id')->prepend('Sin ruta', '__none')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        if ($data['value'] === '__none') {
                            return $query->whereNotExists(function ($subquery): void {
                                $subquery->select(DB::raw(1))
                                    ->from('lv_piv_ruta_municipio as ruta_municipio')
                                    ->join('piv', 'piv.municipio', '=', DB::raw('ruta_municipio.municipio_modulo_id'))
                                    ->whereColumn('piv.piv_id', 'lv_averia_icca.piv_id');
                            });
                        }

                        return $query->whereExists(function ($subquery) use ($data): void {
                            $subquery->select(DB::raw(1))
                                ->from('lv_piv_ruta_municipio as ruta_municipio')
                                ->join('piv', 'piv.municipio', '=', DB::raw('ruta_municipio.municipio_modulo_id'))
                                ->whereColumn('piv.piv_id', 'lv_averia_icca.piv_id')
                                ->where('ruta_municipio.ruta_id', $data['value']);
                        });
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth('3xl')
                    ->infolist(fn (Infolist $infolist): Infolist => self::infolist($infolist)),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Avería ICCA')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('sgip_id')->label('SGIP')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('panel_id_sgip')->label('Panel SGIP')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('activa')->label('Activa')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Sí' : 'No')->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                    Infolists\Components\TextEntry::make('categoria')->badge()->color(fn (string $state): string => self::categoriaColor($state)),
                    Infolists\Components\TextEntry::make('estado_externo')->label('Estado externo'),
                    Infolists\Components\TextEntry::make('asignada_a')->label('Asignada a'),
                    Infolists\Components\TextEntry::make('piv_parada')->label('PIV')->getStateUsing(fn (LvAveriaIcca $record): string => $record->piv?->parada_cod ? trim((string) $record->piv->parada_cod) : '—')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('fecha_import')->label('Fecha import')->dateTime('Y-m-d H:i')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('archivo_origen')->label('Archivo'),
                ]),
            Infolists\Components\Section::make('Descripción')
                ->schema([
                    Infolists\Components\TextEntry::make('descripcion')
                        ->hiddenLabel()
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
            Infolists\Components\Section::make('Notas')
                ->schema([
                    Infolists\Components\TextEntry::make('notas')
                        ->hiddenLabel()
                        ->placeholder('—')
                        ->extraAttributes(['data-mono' => true, 'style' => 'white-space: pre-wrap;'])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLvAveriaIccas::route('/'),
        ];
    }

    /** @return array<string, string> */
    private static function categoriaOptions(): array
    {
        return [
            LvAveriaIcca::CAT_COMUNICACION => LvAveriaIcca::CAT_COMUNICACION,
            LvAveriaIcca::CAT_APAGADO => LvAveriaIcca::CAT_APAGADO,
            LvAveriaIcca::CAT_TIEMPOS => LvAveriaIcca::CAT_TIEMPOS,
            LvAveriaIcca::CAT_AUDIO => LvAveriaIcca::CAT_AUDIO,
            LvAveriaIcca::CAT_OTRAS => LvAveriaIcca::CAT_OTRAS,
        ];
    }

    private static function categoriaColor(string $categoria): string
    {
        return match ($categoria) {
            LvAveriaIcca::CAT_COMUNICACION => 'warning',
            LvAveriaIcca::CAT_APAGADO => 'danger',
            LvAveriaIcca::CAT_TIEMPOS => 'info',
            LvAveriaIcca::CAT_AUDIO => 'primary',
            default => 'gray',
        };
    }
}
