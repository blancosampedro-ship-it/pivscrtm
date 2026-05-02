# Bloque 11ab — Admin TecnicoResource (Filament CRUD)

## Contexto

Bloque 11a (PR #25) implementó la PWA técnico — login + dashboard. Smoke real reveló un gap de producto: **no existe UI admin para crear/gestionar técnicos**. La única forma actual de crear un técnico sería tocando la tabla legacy `tecnico` directamente, lo cual:

1. No es viable para producción (admin debe poder hacerlo).
2. Bloquea el smoke real de 11a (no hay credenciales de técnico activas para probar el login).

Este bloque añade el `TecnicoResource` Filament que faltaba — list, create, edit, activar/desactivar. Patrón análogo a `PivResource` y `AsignacionResource`. Una vez mergeado, admin puede crear un técnico via UI, y luego smokeamos 11a propiamente.

**Contexto técnico clave para el password handling:**

- La tabla legacy `tecnico` tiene columna `clave` con hash SHA1 (legacy de 2014).
- `LegacyHashGuard` (Bloque 06) lee `tecnico.clave` para validar SHA1, y migra a bcrypt en `lv_users` al primer login del técnico.
- **Cuando admin crea un técnico, escribimos `tecnico.clave = sha1($password)`.** NO bcrypt — porque LegacyHashGuard espera SHA1 en esa columna como source of truth legacy. La migración a bcrypt ocurre lazy en el primer PWA login, no en admin-create.

Esto es la única "rareza" de seguridad del bloque y debe quedar bien documentada en el código (comment en el form handler).

## Restricciones inviolables que aplican

- **Regla #3 RGPD:** los campos sensibles (`dni`, `n_seguridad_social`, `ccc`, `telefono`, `direccion`, `email`, `carnet_conducir`) son visibles para el admin pero **bajo ningún concepto** se incluyen en exports al operador-cliente. Bloque 10 ya implementó `TecnicoExportTransformer::forOperador()` con el blacklist. Este bloque NO modifica el transformer — solo añade la UI admin.
- **Latin1String cast existente** en `nombre_completo` y `direccion` (Tecnico.php:42-44) maneja UTF-8 ↔ latin1 automáticamente. NO añadir lógica de encoding adicional.
- **`clave` SHA1 obligatorio:** Cuando admin crea/cambia password, escribir `sha1($plainPassword)` en `tecnico.clave`. NUNCA bcrypt directamente — LegacyHashGuard depende de SHA1 en esa columna. Comment en el form handler explicando por qué.
- **NO delete físico de técnicos.** "Desactivar" = `status = 0`. "Activar" = `status = 1`. Conserva histórico (asignaciones, correctivos, etc. se mantienen referenciando al técnico inactivo).
- **DESIGN.md §11.4 IA:** sidebar groups establecidos (Operaciones, Activos, Reportes). Añadir grupo nuevo **"Personas"** para Técnicos (y futuro Operadores en Bloque 12). Iconos Heroicons coherentes con el resto.
- **Carbon visual (DESIGN.md §10.1):** kebab ActionGroup compacto (NO `->button()`, lección Bloque 09c). Inputs bottom-border (Bloque 09d), buttons 0px radius. `data-mono` en columnas de IDs. Formularios con secciones visuales claras (`Section::make()`).
- **Bloque 09b/10/11a** establecidos. NO romper tests existentes. Suite actual: 162 tests (post-merge 11a) o 156 si 11a no está mergeado todavía. Sumar ~7 tests nuevos.

## Plan de cambios

### 1. `app/Filament/Resources/TecnicoResource.php` — nuevo

Resource Filament estándar. Patrón análogo a `PivResource` para mantener coherencia visual.

Estructura general:

```php
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

class TecnicoResource extends Resource
{
    protected static ?string $model = Tecnico::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Personas';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'técnico';
    protected static ?string $pluralModelLabel = 'técnicos';

    public static function getNavigationBadge(): ?string
    {
        $count = Tecnico::where('status', 1)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
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
                    // CRÍTICO: este campo se hashea a SHA1 (NO bcrypt) antes de
                    // escribirse en tecnico.clave. LegacyHashGuard lee SHA1 desde
                    // ahí y migra a bcrypt en lv_users en el primer login del
                    // técnico. NO cambiar a bcrypt aquí — romperíamos el lookup
                    // legacy del guard. Ver ADR-0003.
                    Forms\Components\TextInput::make('password_plain')
                        ->label(fn (string $context) => $context === 'create'
                            ? 'Contraseña inicial'
                            : 'Cambiar contraseña')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context) => $context === 'create')
                        ->minLength(8)
                        ->maxLength(72)
                        ->dehydrated(false) // no se guarda directamente — lo procesa el handler
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
                    ->counts(['asignaciones as asignaciones_abiertas_count' => fn ($q) => $q->where('status', 1)])
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
                        true: fn ($q) => $q->where('status', 1),
                        false: fn ($q) => $q->where('status', 0),
                        blank: fn ($q) => $q,
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
                        ->action(function (Tecnico $record) {
                            $record->update(['status' => 0]);
                            Notification::make()
                                ->title('Técnico desactivado')
                                ->body($record->nombre_completo . ' ya no puede acceder a la PWA.')
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
                        ->action(function (Tecnico $record) {
                            $record->update(['status' => 1]);
                            Notification::make()
                                ->title('Técnico reactivado')
                                ->body($record->nombre_completo . ' ya puede acceder a la PWA.')
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
                    Infolists\Components\TextEntry::make('tecnico_id')->label('ID')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('nombre_completo')->label('Nombre completo'),
                    Infolists\Components\TextEntry::make('usuario')->label('Usuario')->extraAttributes(['data-mono' => true]),
                    Infolists\Components\TextEntry::make('email')->label('Email'),
                    Infolists\Components\TextEntry::make('dni')->label('DNI / NIE')->extraAttributes(['data-mono' => true])->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state == 1 ? 'Activo' : 'Inactivo')
                        ->color(fn ($state) => $state == 1 ? 'success' : 'gray'),
                ]),
            Infolists\Components\Section::make('Contacto')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('telefono')->label('Teléfono')->extraAttributes(['data-mono' => true])->placeholder('—'),
                    Infolists\Components\TextEntry::make('direccion')->label('Dirección')->placeholder('—'),
                ]),
            Infolists\Components\Section::make('Documentación')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('n_seguridad_social')->label('NSS')->extraAttributes(['data-mono' => true])->placeholder('—'),
                    Infolists\Components\TextEntry::make('ccc')->label('CCC')->extraAttributes(['data-mono' => true])->placeholder('—'),
                    Infolists\Components\TextEntry::make('carnet_conducir')->label('Carnet')->extraAttributes(['data-mono' => true])->placeholder('—'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTecnicos::route('/'),
            'create' => Pages\CreateTecnico::route('/create'),
            'edit'   => Pages\EditTecnico::route('/{record}/edit'),
        ];
    }
}
```

### 2. `app/Filament/Resources/TecnicoResource/Pages/CreateTecnico.php` — nuevo

Override `mutateFormDataBeforeCreate` para hashear el password con SHA1 y escribirlo en `clave`. NO bcrypt.

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\TecnicoResource\Pages;

use App\Filament\Resources\TecnicoResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTecnico extends CreateRecord
{
    protected static string $resource = TecnicoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // El form mete la password plana en `password_plain` (dehydrated=false).
        // Aquí la convertimos a SHA1 y la escribimos en la columna legacy `clave`.
        // CRÍTICO: NO bcrypt. LegacyHashGuard espera SHA1 aquí; la migración a
        // bcrypt ocurre lazy en lv_users durante el primer login PWA del técnico.
        $plain = $data['password_plain'] ?? null;
        unset($data['password_plain']);

        if ($plain) {
            $data['clave'] = sha1($plain);
        }

        return $data;
    }
}
```

### 3. `app/Filament/Resources/TecnicoResource/Pages/EditTecnico.php` — nuevo

Override `mutateFormDataBeforeSave` para hashear el password si admin lo cambió, mantenerlo si dejó blank.

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\TecnicoResource\Pages;

use App\Filament\Resources\TecnicoResource;
use Filament\Resources\Pages\EditRecord;

class EditTecnico extends EditRecord
{
    protected static string $resource = TecnicoResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Mismo principio que CreateTecnico, pero condicional: si admin no
        // tocó el campo, no sobreescribimos `clave` (mantiene la actual).
        $plain = $data['password_plain'] ?? null;
        unset($data['password_plain']);

        if ($plain) {
            $data['clave'] = sha1($plain);
        }

        return $data;
    }

    // Importante: NO mostrar la `clave` actual en el form. El input
    // `password_plain` siempre arranca vacío (porque dehydrated=false).
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['clave']);
        return $data;
    }
}
```

### 4. `app/Filament/Resources/TecnicoResource/Pages/ListTecnicos.php` — nuevo

Mínimo, header action "Crear técnico":

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\TecnicoResource\Pages;

use App\Filament\Resources\TecnicoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTecnicos extends ListRecords
{
    protected static string $resource = TecnicoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Crear técnico'),
        ];
    }
}
```

### 5. `database/factories/TecnicoFactory.php` — verificar / crear si no existe

Si el factory ya existe (Bloque 06), añadir/verificar que tenga los campos necesarios para los tests. Si no existe, crearlo:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tecnico;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tecnico>
 */
class TecnicoFactory extends Factory
{
    protected $model = Tecnico::class;

    public function definition(): array
    {
        return [
            'tecnico_id'         => fake()->unique()->numberBetween(80000, 99999),
            'usuario'            => fake()->unique()->userName(),
            'clave'              => sha1('factory-default-pass'),
            'email'              => fake()->unique()->safeEmail(),
            'nombre_completo'    => fake()->name(),
            'dni'                => fake()->numerify('########').fake()->randomLetter(),
            'carnet_conducir'    => 'B' . fake()->numerify('########'),
            'direccion'          => fake()->streetAddress(),
            'ccc'                => fake()->numerify('############'),
            'n_seguridad_social' => fake()->numerify('############'),
            'telefono'           => fake()->phoneNumber(),
            'status'             => 1,
        ];
    }
}
```

### 6. Tests DoD — `tests/Feature/Filament/Bloque11abAdminTecnicosTest.php`

7 tests funcionales. Crear con `RefreshDatabase`. Los tests usan `Livewire\Livewire::test(TecnicoResource\Pages\CreateTecnico::class)` para drivear el form.

```php
<?php

declare(strict_types=1);

use App\Auth\LegacyHashGuard;
use App\Filament\Resources\TecnicoResource;
use App\Filament\Resources\TecnicoResource\Pages;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('tecnico_resource_visible_in_sidebar_under_personas', function () {
    expect(TecnicoResource::shouldRegisterNavigation())->toBeTrue();
    expect(TecnicoResource::getNavigationGroup())->toBe('Personas');
});

it('admin_can_create_tecnico_with_initial_password_hashed_as_sha1', function () {
    Livewire::test(Pages\CreateTecnico::class)
        ->fillForm([
            'nombre_completo' => 'Juan Pérez Test',
            'usuario'         => 'jperez_test',
            'email'           => 'jperez.test@winfin.local',
            'dni'             => '12345678A',
            'password_plain'  => 'SECRET-test-pass-1',
            'status'          => true,
        ])
        ->call('create')
        ->assertHasNoErrors();

    $created = Tecnico::where('email', 'jperez.test@winfin.local')->first();
    expect($created)->not->toBeNull();
    expect($created->clave)->toBe(sha1('SECRET-test-pass-1'));
    expect((int) $created->status)->toBe(1);
});

it('created_tecnico_can_login_via_legacy_hash_guard_end_to_end', function () {
    // Test crítico: el flujo completo "admin crea técnico" → "técnico hace login PWA" funciona.
    Livewire::test(Pages\CreateTecnico::class)
        ->fillForm([
            'nombre_completo' => 'Test E2E',
            'usuario'         => 'test_e2e',
            'email'           => 'test.e2e@winfin.local',
            'password_plain'  => 'mySecretPass-e2e!',
            'status'          => true,
        ])
        ->call('create')
        ->assertHasNoErrors();

    // Logout admin del actingAs anterior
    auth()->logout();

    // Login del nuevo técnico via Volt component (Bloque 11a)
    $response = Livewire::test('tecnico.login')
        ->set('email', 'test.e2e@winfin.local')
        ->set('password', 'mySecretPass-e2e!')
        ->call('login');

    $response->assertHasNoErrors();
    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->isTecnico())->toBeTrue();
});

it('admin_editing_tecnico_without_password_change_preserves_clave', function () {
    $tecnico = Tecnico::factory()->create([
        'tecnico_id' => 88001,
        'clave'      => sha1('original-pass'),
    ]);

    Livewire::test(Pages\EditTecnico::class, ['record' => $tecnico->getRouteKey()])
        ->fillForm([
            'nombre_completo' => 'Nombre Actualizado',
            'password_plain'  => '', // blank — no cambia password
        ])
        ->call('save')
        ->assertHasNoErrors();

    $tecnico->refresh();
    expect($tecnico->clave)->toBe(sha1('original-pass'));
    expect($tecnico->nombre_completo)->toBe('Nombre Actualizado');
});

it('admin_editing_tecnico_with_password_change_updates_clave_to_new_sha1', function () {
    $tecnico = Tecnico::factory()->create([
        'tecnico_id' => 88002,
        'clave'      => sha1('original-pass'),
    ]);

    Livewire::test(Pages\EditTecnico::class, ['record' => $tecnico->getRouteKey()])
        ->fillForm([
            'password_plain' => 'new-rotated-pass-123',
        ])
        ->call('save')
        ->assertHasNoErrors();

    $tecnico->refresh();
    expect($tecnico->clave)->toBe(sha1('new-rotated-pass-123'));
    expect($tecnico->clave)->not->toBe(sha1('original-pass'));
});

it('admin_can_deactivate_tecnico', function () {
    $tecnico = Tecnico::factory()->create([
        'tecnico_id' => 88003,
        'status'     => 1,
    ]);

    Livewire::test(Pages\ListTecnicos::class)
        ->callTableAction('deactivate', $tecnico);

    $tecnico->refresh();
    expect((int) $tecnico->status)->toBe(0);
});

it('admin_can_reactivate_inactive_tecnico', function () {
    $tecnico = Tecnico::factory()->create([
        'tecnico_id' => 88004,
        'status'     => 0,
    ]);

    Livewire::test(Pages\ListTecnicos::class)
        ->callTableAction('activate', $tecnico);

    $tecnico->refresh();
    expect((int) $tecnico->status)->toBe(1);
});
```

**Importante:** los tests usan `Livewire::test(Pages\CreateTecnico::class)`. Si Filament 3.2 requiere mountar el resource diferente (`->mountAction()` etc.), pivot legítimo. NO degradar a tests source-grep. Si el flujo Livewire no se puede automatizar, escalar al usuario.

## Verificación obligatoria antes del commit final

1. **Tests:** `vendor/bin/pest` → 156 (o 162 si 11a ya está mergeado) + 7 nuevos = ~163-169 verde.
2. **Pint:** `vendor/bin/pint --test` OK sobre los nuevos PHP.
3. **Build:** `npm run build` OK.
4. **Smoke HTTP:**
   - `curl -sI http://127.0.0.1:8000/admin/tecnicos` → 200 ó 302.
   - `curl -sI http://127.0.0.1:8000/admin/tecnicos/create` → 200 ó 302.
5. **CI:** push → 3/3 verde.

## Smoke real obligatorio (post-merge, a cargo del usuario)

Este bloque desbloquea el smoke pendiente de 11a. Flujo:

1. **Admin login** en `/admin/login` con `info@winfin.es`.
2. **Sidebar:** verificar nuevo grupo **"Personas"** con item "Técnicos" (badge con count de activos).
3. **`/admin/tecnicos` listing:** vista tabla con técnicos legacy de prod (~65 filas, 3 con status=1). Carbon visual: kebab compacto, cards rectangulares, status badges, etc.
4. **Crear técnico de test** via botón "Crear técnico":
   - Form en 4 secciones: Identidad / Contacto / Documentación / Acceso.
   - Inputs bottom-border (Carbon).
   - Rellenar nombre, usuario único, email único, password ≥8 chars.
   - Submit → vuelve al listing, ve el técnico creado con status "Activo".
5. **Verificar BD:** opcional, `php artisan tinker` → `DB::table('tecnico')->where('email', 'el.email.usado')->first(['email', 'clave', 'status'])` → debe mostrar `clave` con hash SHA1 de 40 chars.
6. **Smoke 11a (ahora desbloqueado):**
   - Logout admin.
   - Ir a `/tecnico/login` (viewport mobile en Safari Cmd+Opt+R o iPhone).
   - Login con email + password del técnico recién creado.
   - Verificar redirect a `/tecnico` con dashboard "Mis asignaciones abiertas" (probablemente vacío — el técnico nuevo no tiene asignaciones aún).
   - Verificar wordmark "Win*f*in PIV", logout funciona, redirect a /tecnico/login limpio.
7. **Desactivar el técnico** desde admin (ActionGroup → Desactivar → confirmar).
8. **Re-intentar login del técnico desactivado:** debe rechazar con "Cuenta de técnico inactiva".
9. **Reactivar y volver a probar login:** debe funcionar.
10. **Cleanup opcional:** si no quieres mantener el técnico de test en prod, edítalo a status=0 y déjalo. NO borrarlo (regla del bloque: no delete).

## Definition of Done

- 1 PR (#26) con 1-2 commits coherentes:
  - `feat(filament): add TecnicoResource for admin CRUD with sha1 password handling`
  - (opcional) `test: cover Bloque 11ab admin tecnicos resource`
- CI 3/3 verde.
- ~163-169 tests verde.
- Working tree clean tras push.
- PR review-ready (no draft).

## Reporte final que Copilot debe entregar

- SHAs de los commits.
- Diff resumen.
- Estado CI tras push.
- Confirmación HTTP de los 2 endpoints del smoke local.
- Pivot explícito si:
  - El factory de Tecnico ya existía y se modificó (decir cómo).
  - `Livewire::test(Pages\CreateTecnico::class)->fillForm(...)` no funcionó y se cambió a un patrón distinto.
  - Algún campo del form requirió validación adicional que no estaba en el prompt.
- Lista visual pendiente para el usuario (los 10 puntos del smoke real arriba — admin + smoke 11a desbloqueado).
- Confirmación explícita: la `clave` se escribe como SHA1 hash, NUNCA bcrypt. Comment en código justificando.
