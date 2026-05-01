<?php

declare(strict_types=1);

namespace App\Filament\Resources\PivResource\RelationManagers;

use App\Models\Averia;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AveriasRelationManager extends RelationManager
{
    protected static string $relationship = 'averias';

    protected static ?string $title = 'Histórico de averías';

    protected static ?string $modelLabel = 'avería';

    protected static ?string $pluralModelLabel = 'averías';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DateTimePicker::make('fecha')->seconds(false),
            Forms\Components\Select::make('operador_id')
                ->relationship('operador', 'razon_social')
                ->searchable()->preload(),
            Forms\Components\Select::make('tecnico_id')
                ->relationship('tecnico', 'nombre_completo')
                ->searchable()->preload()->nullable(),
            Forms\Components\Textarea::make('notas')->rows(3)->maxLength(500)->columnSpanFull(),
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
                'operador:operador_id,razon_social',
                'asignacion:asignacion_id,averia_id,tipo,status',
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('averia_id')
                    ->label('ID')
                    ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 5, '0', STR_PAD_LEFT))
                    ->extraAttributes(['data-mono' => true])
                    ->sortable()->searchable(),
                Tables\Columns\TextColumn::make('fecha')
                    ->dateTime('d M Y · H:i')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
                Tables\Columns\TextColumn::make('tipo_asignacion')
                    ->label('Tipo')
                    ->badge()
                    ->getStateUsing(fn (Averia $record) => match ((int) ($record->asignacion?->tipo ?? 0)) {
                        1 => 'Correctivo',
                        2 => 'Revisión',
                        default => 'Sin asignar',
                    })
                    ->color(fn (Averia $record) => match ((int) ($record->asignacion?->tipo ?? 0)) {
                        1 => 'danger',
                        2 => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('asignacion_horario')
                    ->label('Horario')
                    ->getStateUsing(fn (Averia $record) => $record->asignacion?->hora_inicial && $record->asignacion?->hora_final
                        ? sprintf('%02d–%02d h', $record->asignacion->hora_inicial, $record->asignacion->hora_final)
                        : '—')
                    ->extraAttributes(['data-mono' => true])
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('asignacion_status')
                    ->label('Status asig.')
                    ->getStateUsing(fn (Averia $record) => $record->asignacion?->status ?? '—')
                    ->badge()
                    ->extraAttributes(['data-mono' => true])
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tecnico_nombre')
                    ->label('Técnico')
                    ->getStateUsing(fn (Averia $record) => $record->tecnico?->nombre_completo)
                    ->placeholder('—')
                    ->limit(25),
                Tables\Columns\TextColumn::make('operador_nombre')
                    ->label('Operador reporta')
                    ->getStateUsing(fn (Averia $record) => $record->operador?->razon_social)
                    ->placeholder('—')
                    ->limit(25),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('notas')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
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
                Tables\Filters\SelectFilter::make('status')
                    ->options([1 => 'Abierta', 2 => 'Cerrada']),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth('xl')
                    ->infolist(fn (Infolist $infolist) => self::infolistSchema($infolist)),
                Tables\Actions\EditAction::make()->iconButton(),
            ]);
    }

    public static function infolistSchema(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Grid::make(3)->schema([
                Infolists\Components\TextEntry::make('averia_id')
                    ->label('ID')
                    ->extraAttributes(['data-mono' => true]),
                Infolists\Components\TextEntry::make('fecha')
                    ->dateTime('d M Y · H:i')
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('status')
                    ->badge()
                    ->placeholder('—'),
            ]),
            Infolists\Components\TextEntry::make('tipo_asignacion')
                ->label('Tipo de asignación')
                ->badge()
                ->getStateUsing(fn (Averia $record) => match ((int) ($record->asignacion?->tipo ?? 0)) {
                    1 => 'Correctivo',
                    2 => 'Revisión rutinaria',
                    default => 'Sin asignación',
                })
                ->color(fn (Averia $record) => match ((int) ($record->asignacion?->tipo ?? 0)) {
                    1 => 'danger',
                    2 => 'success',
                    default => 'gray',
                }),
            Infolists\Components\TextEntry::make('tecnico_nombre')
                ->label('Técnico asignado')
                ->getStateUsing(fn (Averia $record) => $record->tecnico?->nombre_completo ?? '—'),
            Infolists\Components\TextEntry::make('operador_reporta')
                ->label('Operador reporta')
                ->getStateUsing(fn (Averia $record) => $record->operador?->razon_social ?? '—'),
            Infolists\Components\TextEntry::make('notas')
                ->placeholder('— Sin notas —')
                ->columnSpanFull(),
        ]);
    }
}
