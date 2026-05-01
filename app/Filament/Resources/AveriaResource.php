<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AveriaResource\Pages;
use App\Models\Averia;
use App\Models\Piv;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AveriaResource extends Resource
{
    protected static ?string $model = Averia::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $modelLabel = 'avería';

    protected static ?string $pluralModelLabel = 'averías';

    protected static ?string $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'piv:piv_id,parada_cod,direccion,municipio,operador_id',
            'piv.municipioModulo:modulo_id,nombre',
            'piv.operadorPrincipal:operador_id,razon_social',
            'tecnico:tecnico_id,nombre_completo',
            'operador:operador_id,razon_social',
            'asignacion:asignacion_id,averia_id,tipo,status',
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('averia_id')
                        ->label('ID Avería')
                        ->numeric()
                        ->required()
                        ->disabled(fn (string $context) => $context === 'edit')
                        ->dehydrated(fn (string $context) => $context === 'create'),
                    Forms\Components\DateTimePicker::make('fecha')
                        ->label('Fecha y hora')
                        ->seconds(false),
                    Forms\Components\TextInput::make('status')
                        ->label('Status')
                        ->numeric()
                        ->default(1),
                ]),

            Forms\Components\Section::make('Panel y participantes')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('piv_id')
                        ->label('Panel PIV')
                        ->relationship('piv', 'parada_cod')
                        ->searchable(['piv_id', 'parada_cod', 'direccion'])
                        ->preload()
                        ->getOptionLabelFromRecordUsing(fn (Piv $r) => '#'.$r->piv_id.' · '.trim($r->parada_cod ?? '').' · '.($r->direccion ?? '—'))
                        ->required(),
                    Forms\Components\Select::make('operador_id')
                        ->label('Operador reporta')
                        ->relationship('operador', 'razon_social')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('tecnico_id')
                        ->label('Técnico asignado (inicial)')
                        ->relationship('tecnico', 'nombre_completo')
                        ->searchable()
                        ->preload()
                        ->nullable(),
                ]),

            Forms\Components\Section::make('Notas')
                ->schema([
                    Forms\Components\Textarea::make('notas')
                        ->label('Notas del operador')
                        ->rows(4)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextColumn::make('averia_id')
                    ->label('ID')
                    ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 5, '0', STR_PAD_LEFT))
                    ->extraAttributes(['data-mono' => true])
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha')
                    ->dateTime('d M Y · H:i')
                    ->sortable()
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('piv.parada_cod')
                    ->label('Parada')
                    ->formatStateUsing(fn ($state) => mb_strtoupper(trim((string) $state)))
                    ->extraAttributes(['data-mono' => true])
                    ->searchable(),
                Tables\Columns\TextColumn::make('piv.municipioModulo.nombre')
                    ->label('Municipio')
                    ->default('—')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('operador.razon_social')
                    ->label('Operador')
                    ->limit(20)
                    ->color('gray'),
                Tables\Columns\TextColumn::make('tecnico.nombre_completo')
                    ->label('Técnico')
                    ->limit(20)
                    ->placeholder('—')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('asignacion.tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        1 => 'Correctivo',
                        2 => 'Revisión',
                        default => '—',
                    })
                    ->color(fn ($state) => match ((int) $state) {
                        1 => 'danger',
                        2 => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('notas')
                    ->label('Notas')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([1 => 'Abierta', 2 => 'Cerrada', 4 => 'Status 4']),
                Tables\Filters\SelectFilter::make('tecnico_id')
                    ->label('Técnico')
                    ->relationship('tecnico', 'nombre_completo')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('fecha_range')
                    ->form([
                        Forms\Components\DatePicker::make('desde'),
                        Forms\Components\DatePicker::make('hasta'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
                            ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('fecha', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth('2xl')
                    ->infolist(fn (Infolist $i) => self::infolist($i)),
                Tables\Actions\EditAction::make()->iconButton(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Avería')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('averia_id')->label('ID')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('fecha')->dateTime('d M Y · H:i')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('status')->badge(),
                    Infolists\Components\TextEntry::make('asignacion.tipo')
                        ->label('Tipo')
                        ->badge()
                        ->formatStateUsing(fn ($state) => match ((int) $state) {
                            1 => 'Correctivo',
                            2 => 'Revisión',
                            default => '—',
                        })
                        ->color(fn ($state) => match ((int) $state) {
                            1 => 'danger',
                            2 => 'success',
                            default => 'gray',
                        }),
                ]),

            Infolists\Components\Section::make('Panel afectado')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('piv.parada_cod')->label('Parada')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('piv.direccion')->label('Dirección'),
                    Infolists\Components\TextEntry::make('piv.municipioModulo.nombre')->label('Municipio')->placeholder('—'),
                    Infolists\Components\TextEntry::make('piv.operadorPrincipal.razon_social')->label('Operador panel')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Participantes')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('operador.razon_social')->label('Operador reporta')->placeholder('—'),
                    Infolists\Components\TextEntry::make('tecnico.nombre_completo')->label('Técnico asignado')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Notas')
                ->schema([
                    Infolists\Components\TextEntry::make('notas')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAverias::route('/'),
            'create' => Pages\CreateAveria::route('/create'),
            'edit' => Pages\EditAveria::route('/{record}/edit'),
        ];
    }
}
