<?php

declare(strict_types=1);

namespace App\Filament\Resources\PivZonaResource\RelationManagers;

use App\Models\Modulo;
use App\Models\Piv;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MunicipiosRelationManager extends RelationManager
{
    protected static string $relationship = 'municipios';

    protected static ?string $title = 'Municipios asignados';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('municipio_modulo_id')
                ->label('Municipio')
                ->options(fn () => Modulo::municipios()
                    ->orderBy('nombre')
                    ->pluck('nombre', 'modulo_id'))
                ->searchable()
                ->required()
                ->unique(table: 'lv_piv_zona_municipio', column: 'municipio_modulo_id', ignoreRecord: true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('municipio_modulo_id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('modulo:modulo_id,nombre'))
            ->columns([
                Tables\Columns\TextColumn::make('modulo.nombre')
                    ->label('Municipio')
                    ->sortable(),
                Tables\Columns\TextColumn::make('paneles_count')
                    ->label('Paneles activos')
                    ->state(fn ($record) => Piv::query()
                        ->where('status', 1)
                        ->where('municipio', (string) $record->municipio_modulo_id)
                        ->notArchived()
                        ->count())
                    ->badge(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Asignar municipio'),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()->label('Quitar de zona'),
            ]);
    }
}
