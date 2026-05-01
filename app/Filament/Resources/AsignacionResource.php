<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AsignacionResource\Pages;
use App\Models\Asignacion;
use App\Models\Averia;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AsignacionResource extends Resource
{
    protected static ?string $model = Asignacion::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $modelLabel = 'asignación';

    protected static ?string $pluralModelLabel = 'asignaciones';

    protected static ?string $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 2;

    // Override del pluralizador inglés de Filament (Asignacion → asignacions ❌).
    protected static ?string $slug = 'asignaciones';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'averia:averia_id,piv_id,operador_id,notas,fecha,status',
            'averia.piv:piv_id,parada_cod,municipio,operador_id',
            'averia.piv.municipioModulo:modulo_id,nombre',
            'averia.operador:operador_id,razon_social',
            'tecnico:tecnico_id,nombre_completo',
            'correctivo:correctivo_id,asignacion_id,estado_final',
            'revision:revision_id,asignacion_id',
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Asignación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('asignacion_id')
                        ->label('ID')
                        ->numeric()
                        ->required()
                        ->disabled(fn (string $context) => $context === 'edit')
                        ->dehydrated(fn (string $context) => $context === 'create'),
                    Forms\Components\DatePicker::make('fecha')->label('Fecha'),
                    Forms\Components\Select::make('tipo')
                        ->label('Tipo')
                        ->options([
                            1 => 'Correctivo (avería real)',
                            2 => 'Revisión rutinaria',
                        ])
                        ->required(),
                    Forms\Components\TextInput::make('hora_inicial')
                        ->label('Hora inicio')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(24)
                        ->placeholder('Ej. 8'),
                    Forms\Components\TextInput::make('hora_final')
                        ->label('Hora fin')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(24)
                        ->placeholder('Ej. 10'),
                    Forms\Components\TextInput::make('status')->numeric()->default(1),
                ]),

            Forms\Components\Section::make('Avería relacionada')
                ->schema([
                    Forms\Components\Select::make('averia_id')
                        ->label('Avería (toda asignación requiere una — incluso revisiones rutinarias usan avería stub)')
                        ->relationship('averia', 'averia_id')
                        ->searchable(['averia_id', 'notas'])
                        ->preload()
                        ->getOptionLabelFromRecordUsing(fn (Averia $r) => '#'.$r->averia_id.' · '.substr($r->notas ?? '—', 0, 60))
                        ->required(),
                    Forms\Components\Select::make('tecnico_id')
                        ->label('Técnico')
                        ->relationship('tecnico', 'nombre_completo')
                        ->searchable()
                        ->preload(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
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
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d M Y')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
                Tables\Columns\TextColumn::make('horario')
                    ->label('Horario')
                    ->getStateUsing(fn (Asignacion $r) => $r->hora_inicial && $r->hora_final ? sprintf('%02d–%02d h', $r->hora_inicial, $r->hora_final) : '—')
                    ->extraAttributes(['data-mono' => true])
                    ->color('gray'),
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Tipo')
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
                Tables\Columns\TextColumn::make('tecnico.nombre_completo')
                    ->label('Técnico')
                    ->limit(25)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('averia.piv.parada_cod')
                    ->label('Parada')
                    ->formatStateUsing(fn ($state) => mb_strtoupper(trim((string) $state)))
                    ->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('averia.piv.municipioModulo.nombre')
                    ->label('Municipio')
                    ->default('—')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
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
                        default => 'Sin tipo definido',
                    }),
            ])
            ->defaultGroup('tipo')
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->options([
                        1 => 'Correctivo (avería real)',
                        2 => 'Revisión rutinaria',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([1 => 'Abierta', 2 => 'Cerrada']),
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
            Infolists\Components\Section::make('Asignación')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('asignacion_id')->label('ID')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('fecha')->date('d M Y'),
                    Infolists\Components\TextEntry::make('tipo')
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
                    Infolists\Components\TextEntry::make('horario')
                        ->getStateUsing(fn (Asignacion $r) => $r->hora_inicial && $r->hora_final ? sprintf('%02d–%02d h', $r->hora_inicial, $r->hora_final) : '—'),
                    Infolists\Components\TextEntry::make('tecnico.nombre_completo')->label('Técnico')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')->badge(),
                ]),

            Infolists\Components\Section::make('Avería origen')
                ->schema([
                    Infolists\Components\TextEntry::make('averia.averia_id')->label('Avería')->prefix('#')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('averia.fecha')->dateTime('d M Y · H:i'),
                    Infolists\Components\TextEntry::make('averia.notas')->label('Notas')->columnSpanFull()->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Panel afectado')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('averia.piv.parada_cod')->label('Parada')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('averia.piv.municipioModulo.nombre')->label('Municipio')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Cierre')
                ->description('Form de cierre llegará en Bloque 09 — aquí solo readonly de lo existente')
                ->schema([
                    Infolists\Components\TextEntry::make('correctivo.estado_final')->label('Estado final correctivo')->placeholder('—'),
                    Infolists\Components\TextEntry::make('revision.revision_id')->label('Revisión cerrada')->formatStateUsing(fn ($state) => $state ? 'Sí (id #'.$state.')' : 'No')->placeholder('No'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAsignaciones::route('/'),
            'create' => Pages\CreateAsignacion::route('/create'),
            'edit' => Pages\EditAsignacion::route('/{record}/edit'),
        ];
    }
}
