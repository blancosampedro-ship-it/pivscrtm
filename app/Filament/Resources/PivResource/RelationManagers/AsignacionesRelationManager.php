<?php

declare(strict_types=1);

namespace App\Filament\Resources\PivResource\RelationManagers;

use App\Models\Asignacion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AsignacionesRelationManager extends RelationManager
{
    protected static string $relationship = 'asignaciones';

    protected static ?string $title = 'Histórico de asignaciones';

    protected static ?string $modelLabel = 'asignación';

    protected static ?string $pluralModelLabel = 'asignaciones';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('fecha'),
            Forms\Components\Select::make('tipo')
                ->options([1 => 'Correctivo (avería real)', 2 => 'Revisión rutinaria'])
                ->required(),
            Forms\Components\Select::make('tecnico_id')
                ->relationship('tecnico', 'nombre_completo')
                ->searchable()->preload(),
            Forms\Components\TextInput::make('hora_inicial')->numeric()->minValue(0)->maxValue(24),
            Forms\Components\TextInput::make('hora_final')->numeric()->minValue(0)->maxValue(24),
            Forms\Components\TextInput::make('status')->numeric()->default(1),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->striped()
            ->paginated([25, 50, 100])
            ->modifyQueryUsing(fn (Builder $q) => $q->with([
                'tecnico:tecnico_id,nombre_completo',
                'averia:averia_id,piv_id,notas',
            ]))
            ->recordClasses(fn (Asignacion $record) => match ((int) $record->tipo) {
                1 => 'border-l-4 border-l-danger-500',
                2 => 'border-l-4 border-l-success-500',
                default => 'border-l-4 border-l-gray-300',
            })
            ->columns([
                Tables\Columns\TextColumn::make('asignacion_id')
                    ->label('ID')
                    ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 5, '0', STR_PAD_LEFT))
                    ->extraAttributes(['data-mono' => true])
                    ->sortable()->searchable(),
                Tables\Columns\TextColumn::make('fecha')
                    ->date('d M Y')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
                Tables\Columns\TextColumn::make('horario')
                    ->label('Horario')
                    ->getStateUsing(fn (Asignacion $r) => $r->hora_inicial && $r->hora_final
                        ? sprintf('%02d–%02d h', $r->hora_inicial, $r->hora_final)
                        : '—')
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        1 => 'Correctivo',
                        2 => 'Revisión rutinaria',
                        default => 'Indefinido',
                    })
                    ->color(fn ($state) => match ((int) $state) {
                        1 => 'danger',
                        2 => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('tecnico_nombre')
                    ->label('Técnico')
                    ->getStateUsing(fn (Asignacion $r) => $r->tecnico?->nombre_completo)
                    ->placeholder('—')
                    ->limit(25),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->extraAttributes(['data-mono' => true]),
            ])
            ->defaultSort('fecha', 'desc')
            ->groups([
                Tables\Grouping\Group::make('tipo')
                    ->label('Tipo')
                    ->getTitleFromRecordUsing(fn (Asignacion $r) => match ((int) $r->tipo) {
                        1 => 'Correctivos',
                        2 => 'Revisiones rutinarias',
                        default => 'Sin tipo',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->options([1 => 'Correctivo', 2 => 'Revisión rutinaria']),
                Tables\Filters\SelectFilter::make('status')
                    ->options([1 => 'Abierta', 2 => 'Cerrada']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->slideOver()->modalWidth('xl'),
                Tables\Actions\EditAction::make()->iconButton(),
            ]);
    }
}
