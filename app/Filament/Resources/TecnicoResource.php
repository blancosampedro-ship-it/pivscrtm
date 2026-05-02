<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TecnicoResource\Pages;
use App\Models\Tecnico;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TecnicoResource extends Resource
{
    protected static ?string $model = Tecnico::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Personas';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'técnico';

    protected static ?string $pluralModelLabel = 'técnicos';

    protected static ?string $slug = 'tecnicos';

    public static function getNavigationBadge(): ?string
    {
        $count = Tecnico::where('status', 1)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount([
            'asignaciones as asignaciones_abiertas_count' => fn (Builder $query) => $query->where('status', 1),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identidad')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('nombre_completo')
                        ->label('Nombre completo')
                        ->required()
                        ->maxLength(120),
                    Forms\Components\TextInput::make('usuario')
                        ->label('Usuario (login)')
                        ->required()
                        ->maxLength(50)
                        ->extraAttributes(['data-mono' => true])
                        ->helperText('Identificador interno. Sin espacios, ASCII.'),
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(120)
                        ->unique(table: 'tecnico', column: 'email', ignoreRecord: true),
                    Forms\Components\TextInput::make('dni')
                        ->label('DNI / NIE')
                        ->maxLength(20)
                        ->extraAttributes(['data-mono' => true]),
                ]),

            Forms\Components\Section::make('Contacto')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('telefono')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(30)
                        ->extraAttributes(['data-mono' => true]),
                    Forms\Components\TextInput::make('direccion')
                        ->label('Dirección postal')
                        ->maxLength(200),
                ]),

            Forms\Components\Section::make('Documentación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('n_seguridad_social')
                        ->label('Núm. Seguridad Social')
                        ->maxLength(20)
                        ->extraAttributes(['data-mono' => true]),
                    Forms\Components\TextInput::make('ccc')
                        ->label('CCC')
                        ->maxLength(20)
                        ->extraAttributes(['data-mono' => true]),
                    Forms\Components\TextInput::make('carnet_conducir')
                        ->label('Carnet de conducir')
                        ->maxLength(20)
                        ->extraAttributes(['data-mono' => true]),
                ]),

            Forms\Components\Section::make('Acceso')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('password_plain')
                        ->label(fn (string $context) => $context === 'create' ? 'Contraseña inicial' : 'Cambiar contraseña')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context) => $context === 'create')
                        ->minLength(8)
                        ->maxLength(72)
                        ->helperText(fn (string $context) => $context === 'create'
                            ? 'El técnico podrá cambiarla en su primer login PWA.'
                            : 'Dejar en blanco para mantener la actual.'),
                    Forms\Components\Toggle::make('status')
                        ->label('Activo')
                        ->default(true)
                        ->dehydrateStateUsing(fn ($state) => $state ? 1 : 0)
                        ->helperText('Inactivo = no puede entrar a la PWA. Histórico se conserva.'),
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
                Tables\Columns\TextColumn::make('tecnico_id')
                    ->label('ID')
                    ->extraAttributes(['data-mono' => true])
                    ->size('xs')
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('nombre_completo')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('usuario')
                    ->label('Usuario')
                    ->extraAttributes(['data-mono' => true])
                    ->color('gray')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('asignaciones_abiertas_count')
                    ->label('Asignac. abiertas')
                    ->extraAttributes(['data-mono' => true])
                    ->badge()
                    ->color(fn ($state) => $state > 5 ? 'danger' : ($state > 0 ? 'warning' : 'gray')),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state == 1 ? 'Activo' : 'Inactivo')
                    ->color(fn ($state) => $state == 1 ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('status')
                    ->label('Status')
                    ->placeholder('Todos')
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos')
                    ->queries(
                        true: fn (Builder $query) => $query->where('status', 1),
                        false: fn (Builder $query) => $query->where('status', 0),
                        blank: fn (Builder $query) => $query,
                    ),
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
                    Tables\Actions\Action::make('deactivate')
                        ->label('Desactivar')
                        ->icon('heroicon-o-no-symbol')
                        ->color('warning')
                        ->visible(fn (Tecnico $record) => (int) $record->status === 1)
                        ->requiresConfirmation()
                        ->modalHeading('Desactivar técnico')
                        ->modalDescription('No podrá entrar a la PWA. Sus asignaciones e histórico se conservan.')
                        ->action(function (Tecnico $record): void {
                            $record->update(['status' => 0]);

                            Notification::make()
                                ->title('Técnico desactivado')
                                ->body($record->nombre_completo.' ya no puede acceder a la PWA.')
                                ->warning()
                                ->send();
                        }),
                    Tables\Actions\Action::make('activate')
                        ->label('Activar')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (Tecnico $record) => (int) $record->status === 0)
                        ->requiresConfirmation()
                        ->modalHeading('Reactivar técnico')
                        ->modalDescription('Podrá volver a entrar a la PWA con sus credenciales actuales.')
                        ->action(function (Tecnico $record): void {
                            $record->update(['status' => 1]);

                            Notification::make()
                                ->title('Técnico reactivado')
                                ->body($record->nombre_completo.' ya puede acceder a la PWA.')
                                ->success()
                                ->send();
                        }),
                ])
                    ->label('Acciones')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->size('sm')
                    ->color('gray'),
            ])
            ->defaultSort('nombre_completo');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Identidad')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('tecnico_id')
                        ->label('ID')
                        ->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('nombre_completo')
                        ->label('Nombre completo'),
                    Infolists\Components\TextEntry::make('usuario')
                        ->label('Usuario')
                        ->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('email')
                        ->label('Email'),
                    Infolists\Components\TextEntry::make('dni')
                        ->label('DNI / NIE')
                        ->extraAttributes(['data-mono' => true])
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state == 1 ? 'Activo' : 'Inactivo')
                        ->color(fn ($state) => $state == 1 ? 'success' : 'gray'),
                ]),
            Infolists\Components\Section::make('Contacto')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('telefono')
                        ->label('Teléfono')
                        ->extraAttributes(['data-mono' => true])
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('direccion')
                        ->label('Dirección')
                        ->placeholder('—'),
                ]),
            Infolists\Components\Section::make('Documentación')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('n_seguridad_social')
                        ->label('NSS')
                        ->extraAttributes(['data-mono' => true])
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('ccc')
                        ->label('CCC')
                        ->extraAttributes(['data-mono' => true])
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('carnet_conducir')
                        ->label('Carnet')
                        ->extraAttributes(['data-mono' => true])
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTecnicos::route('/'),
            'create' => Pages\CreateTecnico::route('/create'),
            'edit' => Pages\EditTecnico::route('/{record}/edit'),
        ];
    }
}
