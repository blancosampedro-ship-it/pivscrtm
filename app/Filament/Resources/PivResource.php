<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PivResource\Pages;
use App\Models\Modulo;
use App\Models\Piv;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PivResource extends Resource
{
    protected static ?string $model = Piv::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $modelLabel = 'panel PIV';

    protected static ?string $pluralModelLabel = 'paneles PIV';

    protected static ?string $navigationGroup = 'Activos';

    protected static ?int $navigationSort = 1;

    /**
     * Eager loading obligatorio (DoD Bloque 07). Cubre todas las relaciones
     * mostradas en table() para evitar N+1. Test piv_listing_no_n_plus_one
     * verifica con DB::getQueryLog que count <= 8.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'operadorPrincipal:operador_id,razon_social',
            'industria:modulo_id,nombre',
            'municipioModulo:modulo_id,nombre',
            'imagenes',
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('piv_id')
                        ->label('ID PIV')
                        ->numeric()
                        ->required()
                        ->disabled(fn (string $context) => $context === 'edit')
                        ->dehydrated(fn (string $context) => $context === 'create'),
                    Forms\Components\TextInput::make('parada_cod')->label('Cód. parada')->maxLength(255),
                    Forms\Components\TextInput::make('cc_cod')->label('Cód. CC')->maxLength(255),
                    Forms\Components\TextInput::make('n_serie_piv')->label('N.º serie PIV')->maxLength(255),
                    Forms\Components\TextInput::make('n_serie_sim')->label('N.º serie SIM')->maxLength(255),
                    Forms\Components\TextInput::make('n_serie_mgp')->label('N.º serie MGP')->maxLength(255),
                    Forms\Components\TextInput::make('tipo_piv')->label('Tipo PIV')->maxLength(255),
                    Forms\Components\TextInput::make('tipo_marquesina')->label('Tipo marquesina')->maxLength(255),
                    Forms\Components\TextInput::make('tipo_alimentacion')->label('Tipo alimentación')->maxLength(255),
                ]),

            Forms\Components\Section::make('Localización')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('direccion')->label('Dirección')->maxLength(255)->columnSpanFull(),

                    Forms\Components\Select::make('municipio')
                        ->label('Municipio')
                        ->options(fn () => self::municipioOptions())
                        ->searchable()
                        ->required()
                        ->default('0')
                        ->rules([fn () => self::municipioValidationRule()]),

                    Forms\Components\Select::make('industria_id')
                        ->label('Industria')
                        ->relationship('industria', 'nombre', fn ($query) => $query->where('tipo', Modulo::TIPO_INDUSTRIA))
                        ->searchable()
                        ->preload()
                        ->nullable(),

                    Forms\Components\TextInput::make('concesionaria_id')->label('Concesionaria ID')->numeric()->nullable(),
                ]),

            Forms\Components\Section::make('Operadores')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('operador_id')
                        ->label('Operador principal')
                        ->relationship('operadorPrincipal', 'razon_social')
                        ->searchable()
                        ->preload()
                        ->nullable(),
                    Forms\Components\Select::make('operador_id_2')
                        ->label('Operador secundario')
                        ->relationship('operadorSecundario', 'razon_social')
                        ->searchable()
                        ->preload()
                        ->nullable(),
                    Forms\Components\Select::make('operador_id_3')
                        ->label('Operador terciario')
                        ->relationship('operadorTerciario', 'razon_social')
                        ->searchable()
                        ->preload()
                        ->nullable(),
                ]),

            Forms\Components\Section::make('Estado')
                ->columns(3)
                ->schema([
                    // TODO: refinar a Select cuando se descubra el diccionario de status.
                    Forms\Components\TextInput::make('status')->label('Status')->numeric()->default(1),
                    Forms\Components\TextInput::make('status2')->label('Status2')->numeric()->nullable(),
                    Forms\Components\DatePicker::make('fecha_instalacion')->label('Fecha instalación'),
                    Forms\Components\TextInput::make('mantenimiento')->label('Mantenimiento')->maxLength(45),
                    Forms\Components\Textarea::make('prevision')->label('Previsión')->rows(2)->columnSpanFull(),
                    Forms\Components\Textarea::make('observaciones')->label('Observaciones')->rows(3)->columnSpanFull(),
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
                Tables\Columns\ImageColumn::make('thumbnail_url')
                    ->label('')
                    ->size(28)
                    ->extraImgAttributes(['style' => 'border-radius:4px; object-fit:cover'])
                    ->grow(false),
                Tables\Columns\TextColumn::make('piv_id')
                    ->label('ID')
                    ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 3, '0', STR_PAD_LEFT))
                    ->sortable()
                    ->searchable()
                    ->extraAttributes(['data-mono' => true])
                    ->size('xs')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('parada_cod')
                    ->label('Parada')
                    ->formatStateUsing(fn ($state) => mb_strtoupper(trim((string) $state)))
                    ->searchable()
                    ->sortable()
                    ->extraAttributes(['data-mono' => true])
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('direccion')
                    ->label('Dirección')
                    ->searchable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('municipioModulo.nombre')
                    ->label('Municipio')
                    ->default('—')
                    ->sortable()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('operadorPrincipal.razon_social')
                    ->label('Operador')
                    ->limit(20)
                    ->color('gray'),
                Tables\Columns\TextColumn::make('industria.nombre')
                    ->label('Industria')
                    ->limit(15)
                    ->extraAttributes(['data-mono' => true])
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state == 1 ? 'Operativo' : 'Inactivo')
                    ->color(fn ($state) => $state == 1 ? 'success' : 'danger'),
            ])
            ->defaultSort('piv_id')
            ->groups([
                Tables\Grouping\Group::make('status')
                    ->label('Status')
                    ->getTitleFromRecordUsing(fn ($record) => $record->status == 1 ? 'Operativos' : 'Inactivos / Averiados'),
            ])
            ->defaultGroup('status')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([1 => 'Operativos', 0 => 'Inactivos']),
                Tables\Filters\SelectFilter::make('municipio')
                    ->label('Municipio')
                    ->options(fn () => self::municipioOptions())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('operador_id')
                    ->label('Operador principal')
                    ->relationship('operadorPrincipal', 'razon_social')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth('2xl')
                    ->infolist(fn (Infolist $infolist) => self::infolist($infolist)),
                Tables\Actions\EditAction::make()
                    ->iconButton(),
            ]);
    }

    /**
     * Side-panel inspector (Airtable-mode). Bloque 07d — ver wireframe
     * variant-C-airtable.html. Galería completa + 4 sections de datos.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Galería')
                ->schema([
                    ImageEntry::make('imagenes_urls')
                        ->label('')
                        ->getStateUsing(fn ($record) => $record->imagenes
                            ->map(fn ($img) => 'https://www.winfin.es/images/piv/'.$img->url)
                            ->all())
                        ->height(180)
                        ->limit(6),
                ])
                ->collapsible(false)
                ->compact(),

            InfolistSection::make('Identificación')
                ->columns(3)
                ->schema([
                    TextEntry::make('piv_id')
                        ->label('ID')
                        ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 5, '0', STR_PAD_LEFT))
                        ->extraAttributes(['data-mono' => true]),
                    TextEntry::make('parada_cod')
                        ->label('Parada')
                        ->formatStateUsing(fn ($state) => mb_strtoupper(trim((string) $state)))
                        ->extraAttributes(['data-mono' => true]),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state == 1 ? 'Operativo' : 'Inactivo')
                        ->color(fn ($state) => $state == 1 ? 'success' : 'danger'),
                    TextEntry::make('direccion')->label('Dirección')->columnSpan(3),
                    TextEntry::make('n_serie_piv')->label('Nº serie PIV')->extraAttributes(['data-mono' => true])->placeholder('—'),
                    TextEntry::make('n_serie_sim')->label('Nº serie SIM')->extraAttributes(['data-mono' => true])->placeholder('—'),
                    TextEntry::make('n_serie_mgp')->label('Nº serie MGP')->extraAttributes(['data-mono' => true])->placeholder('—'),
                ]),

            InfolistSection::make('Localización')
                ->columns(2)
                ->schema([
                    TextEntry::make('municipioModulo.nombre')->label('Municipio')->placeholder('— Sin asignar —'),
                    TextEntry::make('industria.nombre')->label('Industria')->placeholder('—'),
                    TextEntry::make('tipo_piv')->label('Tipo PIV')->placeholder('—'),
                    TextEntry::make('tipo_marquesina')->label('Marquesina')->placeholder('—'),
                    TextEntry::make('tipo_alimentacion')->label('Alimentación')->placeholder('—'),
                ]),

            InfolistSection::make('Operadores')
                ->columns(3)
                ->schema([
                    TextEntry::make('operadorPrincipal.razon_social')->label('Principal')->placeholder('—'),
                    TextEntry::make('operadorSecundario.razon_social')->label('Secundario')->placeholder('—'),
                    TextEntry::make('operadorTerciario.razon_social')->label('Terciario')->placeholder('—'),
                ]),

            InfolistSection::make('Estado')
                ->columns(3)
                ->schema([
                    TextEntry::make('mantenimiento')->label('Mantenimiento')->placeholder('—'),
                    TextEntry::make('fecha_instalacion')->label('Instalación')->date('d M Y')->placeholder('—'),
                    TextEntry::make('observaciones')->columnSpan(3)->placeholder('—'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPivs::route('/'),
            'create' => Pages\CreatePiv::route('/create'),
            'edit' => Pages\EditPiv::route('/{record}/edit'),
        ];
    }

    /**
     * Opciones para Select de municipio: centinela "0" + municipios ordenados (ADR-0007).
     *
     * @return array<string, string>
     */
    private static function municipioOptions(): array
    {
        $municipios = Modulo::municipios()
            ->orderBy('nombre')
            ->pluck('nombre', 'modulo_id')
            ->mapWithKeys(fn (string $nombre, int $id) => [(string) $id => $nombre])
            ->all();

        return ['0' => '— Sin municipio asignado —'] + $municipios;
    }

    /**
     * Regla de validación closure para `municipio` (ADR-0007).
     *
     * Acepta: "0" (centinela "sin asignar") o un modulo_id numérico que exista
     * con tipo=5 en la tabla `modulo`.
     */
    private static function municipioValidationRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === '0') {
                return;
            }
            if (! is_string($value) || ! ctype_digit($value)) {
                $fail('El municipio debe ser un id numérico o "0" (sin municipio).');

                return;
            }
            $exists = DB::table('modulo')
                ->where('modulo_id', (int) $value)
                ->where('tipo', Modulo::TIPO_MUNICIPIO)
                ->exists();

            if (! $exists) {
                $fail("El municipio id={$value} no existe en el catálogo (modulo tipo=5).");
            }
        };
    }
}
