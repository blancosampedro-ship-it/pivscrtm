<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PivZonaResource\Pages;
use App\Filament\Resources\PivZonaResource\RelationManagers\MunicipiosRelationManager;
use App\Models\PivZona;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PivZonaResource extends Resource
{
    protected static ?string $model = PivZona::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'zona operativa';

    protected static ?string $pluralModelLabel = 'zonas operativas';

    protected static ?string $slug = 'zonas';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('municipios');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identidad')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('nombre')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(80)
                        ->unique(table: 'lv_piv_zona', column: 'nombre', ignoreRecord: true),
                    Forms\Components\ColorPicker::make('color_hint')
                        ->label('Color')
                        ->nullable()
                        ->regex('/^#[0-9A-Fa-f]{6}$/'),
                    Forms\Components\TextInput::make('sort_order')
                        ->label('Orden')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
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
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Zona')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\ColorColumn::make('color_hint')
                    ->label('Color'),
                Tables\Columns\TextColumn::make('municipios_count')
                    ->label('Municipios')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Orden')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->label('Ver detalle')
                        ->icon('heroicon-o-eye')
                        ->slideOver()
                        ->modalWidth('2xl')
                        ->infolist(fn (Infolist $infolist) => self::infolist($infolist)),
                    Tables\Actions\EditAction::make()
                        ->label('Editar'),
                    Tables\Actions\DeleteAction::make()
                        ->label('Eliminar')
                        ->requiresConfirmation(),
                ])
                    ->label('Acciones')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->size('sm')
                    ->color('gray'),
            ])
            ->defaultSort('sort_order');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Zona')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('nombre')
                        ->label('Nombre'),
                    Infolists\Components\TextEntry::make('color_hint')
                        ->label('Color')
                        ->badge()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('sort_order')
                        ->label('Orden')
                        ->extraAttributes(['data-mono' => true]),
                ]),
            Infolists\Components\Section::make('Municipios asignados')
                ->schema([
                    Infolists\Components\TextEntry::make('municipios_list')
                        ->label('')
                        ->getStateUsing(fn (PivZona $record) => $record->municipios()
                            ->with('modulo:modulo_id,nombre')
                            ->get()
                            ->pluck('modulo.nombre')
                            ->filter()
                            ->sort()
                            ->join(', '))
                        ->placeholder('— Sin municipios asignados —')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            MunicipiosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPivZonas::route('/'),
            'create' => Pages\CreatePivZona::route('/create'),
            'edit' => Pages\EditPivZona::route('/{record}/edit'),
        ];
    }
}
