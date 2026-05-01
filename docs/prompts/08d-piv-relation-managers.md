# Bloque 08d — RelationManagers en PivResource (averías + asignaciones contextuales)

> Copia el bloque BEGIN PROMPT … END PROMPT en Copilot. ~60-75 min.

---

## Causa

La app vieja (`paneles.php?action=edit&id=329#tabs-3`) tiene tabs en la página de edición del panel. Ese `#tabs-3` revela que el modo correcto de consultar averías es **desde el panel**: cada avería pertenece a un panel, así que el flujo natural del admin es entrar al panel → ver su histórico de averías y asignaciones para trazabilidad.

Bloque 08 (PR #13) creó AveriaResource + AsignacionResource como entries top-level del sidebar — peer-level con Paneles. Ese modelado es **incorrecto**: averías/asignaciones son `child` de panel, no peer. Reportes cross-panel (filtros agregados por fecha/operador) sí son legítimos pero pertenecen a Bloque 10 Dashboard, no al menú primario de operaciones.

## Decisión

Refactorizar a **RelationManagers** (patrón canónico Filament 3 para parent-child). El admin entra al panel → tabs "Averías" y "Asignaciones" debajo del infolist con histórico filtrado a ese panel.

### Cambios

1. **Piv model**: nueva relación `asignaciones()` HasManyThrough Asignacion via Averia.
2. **2 RelationManagers** en `app/Filament/Resources/PivResource/RelationManagers/`:
   - `AveriasRelationManager` (tabla con fecha, tipo, tecnico, status, notas).
   - `AsignacionesRelationManager` (tabla con fecha, tipo + stripe lateral cromático regla #11, tecnico, horario, status).
3. **PivResource::getRelations()** → array con los 2 managers.
4. **`ViewPiv.php` page nuevo** que renderiza infolist + tabs.
5. **PivResource action**: `ViewAction::make()` SIN slideOver → navega a la View page con tabs (peek+drill-in en una sola página).
6. **AveriaResource + AsignacionResource**: `shouldRegisterNavigation = false`. Resources siguen accesibles por URL directa (necesario para Bloque 10 cross-panel reports + para deep-link a una avería específica), pero fuera del sidebar primario.
7. **DESIGN.md §10** actualizado con patrón parent-child + tabs como UX canónico.

## Definition of Done

1. `Piv::asignaciones()` HasManyThrough funcional + test.
2. 2 RelationManagers con tabla densa + Airtable-Mode + Filters + ViewAction (slideOver con infolist defensive).
3. `ViewPiv.php` page renderiza infolist + tabs RelationManager.
4. PivResource ViewAction navega a View page (sin slideOver).
5. AveriaResource + AsignacionResource invisibles en sidebar (`shouldRegisterNavigation = false`) pero URL accesible.
6. DESIGN.md §10 documenta pattern parent-child.
7. Tests:
   - `piv_view_page_renders_with_relation_manager_tabs`
   - `averias_relation_manager_shows_only_this_pivs_averias`
   - `asignaciones_relation_manager_shows_only_this_pivs_asignaciones_via_averias`
   - `averia_resource_not_in_admin_sidebar_navigation`
   - `asignacion_resource_not_in_admin_sidebar_navigation`
   - `averia_resource_url_still_accessible_directly` (regression test for Bloque 10).
8. CI 3/3 verde.
9. Smoke real post-merge: `/admin/pivs` → click panel → View page con tabs Averías + Asignaciones del panel solamente.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md (incluyendo patterns Bloque 08b/08c añadidos recientemente).
- DESIGN.md §10 (Patrones críticos del producto).
- docs/prompts/08d-piv-relation-managers.md (este archivo).
- app/Models/Piv.php (HasMany averias ya existe, falta asignaciones HasManyThrough).
- app/Filament/Resources/PivResource.php (resource actual con slideOver).
- app/Filament/Resources/AveriaResource.php (será demoted a no-navigation).
- app/Filament/Resources/AsignacionResource.php (idem).

Tu tarea: refactorizar la IA para parent-child con RelationManagers en PivResource.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch

```bash
git status --short          # esperado: solo este prompt + audit trails ya existentes
git checkout -b bloque-08d-piv-relation-managers
./vendor/bin/pest --colors=never --compact 2>&1 | tail -3
```

120 tests verdes esperados.

PARA: "Branch creada. ¿Procedo a Fase 1 (Piv asignaciones HasManyThrough)?"

## FASE 1 — Piv::asignaciones() HasManyThrough

Lee `app/Models/Piv.php`. Localiza la relación `averias()`. Añade DESPUÉS:

```php
    /**
     * Asignaciones del panel via averías. HasManyThrough porque `asignacion`
     * NO tiene `piv_id` directo — se llega vía `averia.piv_id` (ARCHITECTURE §5.2).
     */
    public function asignaciones(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            \App\Models\Asignacion::class,
            \App\Models\Averia::class,
            'piv_id',           // FK on averia table → piv
            'averia_id',        // FK on asignacion table → averia
            'piv_id',           // local key on piv
            'averia_id'         // local key on averia
        );
    }
```

Verifica con tinker:
```bash
php artisan tinker --execute='
$p = \App\Models\Piv::find(1);
echo "averias count: " . $p->averias()->count() . PHP_EOL;
echo "asignaciones count (HasManyThrough): " . $p->asignaciones()->count() . PHP_EOL;
'
```

PARA: "Fase 1 completa: HasManyThrough funcional. ¿Procedo a Fase 2 (AveriasRelationManager)?"

## FASE 2 — AveriasRelationManager

Genera scaffold:
```bash
php artisan make:filament-relation-manager PivResource averias
```

Esto crea `app/Filament/Resources/PivResource/RelationManagers/AveriasRelationManager.php`. Reescríbelo:

```php
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
            ->modifyQueryUsing(fn (Builder $q) => $q->with(['tecnico:tecnico_id,nombre_completo', 'operador:operador_id,razon_social', 'asignacion:asignacion_id,averia_id,tipo,status']))
            ->columns([
                Tables\Columns\TextColumn::make('averia_id')
                    ->label('ID')
                    ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 5, '0', STR_PAD_LEFT))
                    ->extraAttributes(['data-mono' => true])
                    ->sortable()->searchable(),
                Tables\Columns\TextColumn::make('fecha')->dateTime('d M Y · H:i')->extraAttributes(['data-mono' => true])->sortable(),
                Tables\Columns\TextColumn::make('asignacion.tipo')
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
                Tables\Columns\TextColumn::make('tecnico.nombre_completo')->label('Técnico')->placeholder('—')->limit(25),
                Tables\Columns\TextColumn::make('operador.razon_social')->label('Operador reporta')->placeholder('—')->limit(25),
                Tables\Columns\TextColumn::make('status')->badge()->extraAttributes(['data-mono' => true]),
                Tables\Columns\TextColumn::make('notas')->limit(60)->wrap()->toggleable(),
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
                Tables\Actions\ViewAction::make()->slideOver()->modalWidth('xl')
                    ->infolist(fn (Infolist $infolist) => self::infolistSchema($infolist)),
                Tables\Actions\EditAction::make()->iconButton(),
            ]);
    }

    public static function infolistSchema(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Grid::make(3)->schema([
                Infolists\Components\TextEntry::make('averia_id')->label('ID')->extraAttributes(['data-mono' => true]),
                Infolists\Components\TextEntry::make('fecha')->dateTime('d M Y · H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('status')->badge()->placeholder('—'),
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
            Infolists\Components\TextEntry::make('notas')->placeholder('— Sin notas —')->columnSpanFull(),
        ]);
    }
}
```

PARA: "Fase 2 completa: AveriasRelationManager. ¿Procedo a Fase 3 (AsignacionesRelationManager)?"

## FASE 3 — AsignacionesRelationManager

```bash
php artisan make:filament-relation-manager PivResource asignaciones
```

Reescribe `app/Filament/Resources/PivResource/RelationManagers/AsignacionesRelationManager.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\PivResource\RelationManagers;

use App\Models\Asignacion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
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
            ->modifyQueryUsing(fn (Builder $q) => $q->with(['tecnico:tecnico_id,nombre_completo', 'averia:averia_id,piv_id,notas']))
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
                Tables\Columns\TextColumn::make('fecha')->date('d M Y')->extraAttributes(['data-mono' => true])->sortable(),
                Tables\Columns\TextColumn::make('horario')
                    ->label('Horario')
                    ->getStateUsing(fn (Asignacion $r) => $r->hora_inicial && $r->hora_final ? sprintf('%02d–%02d h', $r->hora_inicial, $r->hora_final) : '—')
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
                Tables\Columns\TextColumn::make('tecnico.nombre_completo')->label('Técnico')->placeholder('—')->limit(25),
                Tables\Columns\TextColumn::make('status')->badge()->extraAttributes(['data-mono' => true]),
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
```

PARA: "Fase 3 completa: AsignacionesRelationManager. ¿Procedo a Fase 4 (PivResource integration + ViewPage)?"

## FASE 4 — PivResource: getRelations + ViewPage

### 4a — Añadir `getRelations()` a `PivResource.php`

Localiza el método `getPages()`. Añade ANTES de `getPages()`:

```php
public static function getRelations(): array
{
    return [
        \App\Filament\Resources\PivResource\RelationManagers\AveriasRelationManager::class,
        \App\Filament\Resources\PivResource\RelationManagers\AsignacionesRelationManager::class,
    ];
}
```

### 4b — Crear ViewPiv page

Crea `app/Filament/Resources/PivResource/Pages/ViewPiv.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\PivResource\Pages;

use App\Filament\Resources\PivResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPiv extends ViewRecord
{
    protected static string $resource = PivResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
```

### 4c — Update `getPages()` en PivResource

Localiza `getPages()` y añade la entrada `view`:

```php
public static function getPages(): array
{
    return [
        'index' => Pages\ListPivs::route('/'),
        'create' => Pages\CreatePiv::route('/create'),
        'view' => Pages\ViewPiv::route('/{record}'),
        'edit' => Pages\EditPiv::route('/{record}/edit'),
    ];
}
```

### 4d — Cambiar ViewAction de la tabla a navegar (sin slideOver)

Localiza el `Tables\Actions\ViewAction::make()` en el `->actions([])` de la tabla. CAMBIA:

```php
// ANTES:
Tables\Actions\ViewAction::make()
    ->slideOver()
    ->modalWidth('2xl')
    ->infolist(fn (Infolist $infolist) => self::infolist($infolist)),

// DESPUÉS — sin slideOver, navega a View page con tabs:
Tables\Actions\ViewAction::make(),
```

NOTA: Filament por defecto navega a la View page si está registrada y no se le pasa `slideOver()`. La infolist sigue siendo la misma — el método `static::infolist(Infolist $infolist)` se invoca en la View page automáticamente.

PARA: "Fase 4 completa: PivResource con tabs. ¿Procedo a Fase 5 (demote AveriaResource + AsignacionResource del sidebar)?"

## FASE 5 — Quitar Averia + Asignacion del sidebar primario

### 5a — `AveriaResource.php`

Localiza la zona de `protected static` properties. Añade:

```php
protected static bool $shouldRegisterNavigation = false;
```

NO borres el resource ni `getPages()` — solo lo escondemos del sidebar. URL `/admin/averias` sigue accesible para Bloque 10 cross-panel reports y para deep-links desde notificaciones.

### 5b — `AsignacionResource.php`

Mismo cambio:

```php
protected static bool $shouldRegisterNavigation = false;
```

PARA: "Fase 5 completa: Resources fuera de sidebar pero URL accesible. ¿Procedo a Fase 6 (DESIGN.md update + tests)?"

## FASE 6 — DESIGN.md update + tests

### 6a — DESIGN.md §10

Lee `DESIGN.md`. Localiza la sección `## 10. Patrones críticos del producto`. Añade un nuevo subapartado al final de §10:

```markdown
### 10.4 Parent-child IA: averías y asignaciones se consultan desde el panel

Las averías y asignaciones NO viven como entries top-level del menú primario. Pertenecen al panel afectado y se consultan vía RelationManager tabs en la View page del panel:

- `/admin/pivs` → click panel → View page con tabs:
  1. **Detalles** — infolist con foto + 5 secciones (Bloque 07d).
  2. **Histórico de averías** — RelationManager tabla densa filtrada al panel.
  3. **Histórico de asignaciones** — RelationManager con stripe lateral cromático regla #11 (10.1) filtrada al panel.

Justificación: cada avería pertenece a un panel — la trazabilidad operativa exige verlas en contexto del panel, no en abstracto. Reportes cross-panel (filtros agregados por fecha/operador, exports CSV/PDF) viven en Bloque 10 Dashboard como uso secundario.

Implementación: Filament 3 RelationManagers (`PivResource::getRelations()`). AveriaResource y AsignacionResource quedan accesibles por URL pero sin entrada en sidebar (`shouldRegisterNavigation = false`). Bloque 10 reincorporará una entrada "Reportes" para vistas agregadas.

Inspiración: la app vieja `winfin.es/paneles.php?action=edit&id=N#tabs-3` ya usaba tabs en la edit del panel — el patrón parent-child está validado por años de uso operativo.
```

Y añade entrada nueva en §11 Decisions Log:

```markdown
| 2026-05-02 | **IA refactorizada a parent-child con RelationManagers**. Averías y asignaciones se consultan desde el panel via tabs (10.4). Top-level entries quitados del sidebar (`shouldRegisterNavigation = false`). | Bloque 08 inicial las puso peer-level con paneles — incorrecto. La app vieja siempre usó tabs (`#tabs-3`); revelado por feedback del usuario tras smoke real. Bloque 08d corrige la arquitectura. Bloque 10 reincorporará una entrada "Reportes" para uso secundario (cross-panel filtros agregados). |
```

### 6b — Tests

Crea `tests/Feature/Filament/PivRelationManagersTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\PivResource\Pages\ViewPiv;
use App\Filament\Resources\PivResource\RelationManagers\AsignacionesRelationManager;
use App\Filament\Resources\PivResource\RelationManagers\AveriasRelationManager;
use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Modulo;
use App\Models\Piv;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('piv_view_page_renders_with_relation_manager_tabs', function () {
    $municipio = Modulo::factory()->municipio('Madrid')->create();
    $piv = Piv::factory()->create(['piv_id' => 88800, 'municipio' => (string) $municipio->modulo_id]);

    Livewire::test(ViewPiv::class, ['record' => $piv->piv_id])
        ->assertSuccessful();
});

it('averias_relation_manager_shows_only_this_pivs_averias', function () {
    $piv1 = Piv::factory()->create(['piv_id' => 88810]);
    $piv2 = Piv::factory()->create(['piv_id' => 88811]);
    $av1 = Averia::factory()->create(['averia_id' => 88810, 'piv_id' => 88810]);
    $av2 = Averia::factory()->create(['averia_id' => 88811, 'piv_id' => 88811]);

    Livewire::test(AveriasRelationManager::class, [
        'ownerRecord' => $piv1,
        'pageClass' => ViewPiv::class,
    ])
        ->assertCanSeeTableRecords([$av1])
        ->assertCanNotSeeTableRecords([$av2]);
});

it('asignaciones_relation_manager_shows_only_this_pivs_asignaciones_via_averias', function () {
    $piv1 = Piv::factory()->create(['piv_id' => 88820]);
    $piv2 = Piv::factory()->create(['piv_id' => 88821]);
    $av1 = Averia::factory()->create(['averia_id' => 88820, 'piv_id' => 88820]);
    $av2 = Averia::factory()->create(['averia_id' => 88821, 'piv_id' => 88821]);
    $asig1 = Asignacion::factory()->create(['asignacion_id' => 88820, 'averia_id' => 88820, 'tipo' => 1]);
    $asig2 = Asignacion::factory()->create(['asignacion_id' => 88821, 'averia_id' => 88821, 'tipo' => 2]);

    Livewire::test(AsignacionesRelationManager::class, [
        'ownerRecord' => $piv1,
        'pageClass' => ViewPiv::class,
    ])
        ->assertCanSeeTableRecords([$asig1])
        ->assertCanNotSeeTableRecords([$asig2]);
});

it('averia_resource_not_in_admin_sidebar_navigation', function () {
    expect(\App\Filament\Resources\AveriaResource::shouldRegisterNavigation())->toBeFalse();
});

it('asignacion_resource_not_in_admin_sidebar_navigation', function () {
    expect(\App\Filament\Resources\AsignacionResource::shouldRegisterNavigation())->toBeFalse();
});

it('averia_resource_url_still_accessible_directly', function () {
    // Regression: aunque no esté en sidebar, la URL /admin/averias funciona (Bloque 10 reportes).
    $this->get(\App\Filament\Resources\AveriaResource::getUrl('index'))->assertOk();
});

it('piv_has_asignaciones_through_averias_relation', function () {
    $piv = Piv::factory()->create(['piv_id' => 88830]);
    $av = Averia::factory()->create(['averia_id' => 88830, 'piv_id' => 88830]);
    Asignacion::factory()->create(['asignacion_id' => 88830, 'averia_id' => 88830]);

    expect($piv->asignaciones()->count())->toBe(1);
});
```

Corre tests:
```bash
./vendor/bin/pest tests/Feature/Filament/ --colors=never --compact 2>&1 | tail -10
./vendor/bin/pest --colors=never --compact 2>&1 | tail -5
```

127+ tests verdes esperados (120 + 7 nuevos).

PARA: "Fase 6 completa: tests verdes + DESIGN.md actualizado. ¿Procedo a Fase 7 (commits + PR)?"

## FASE 7 — Pint + commits + PR

```bash
./vendor/bin/pint --test 2>&1 | tail -3
npm run build 2>&1 | tail -3
```

Stage explícito:
1. `docs: add Bloque 08d prompt + DESIGN.md §10.4 parent-child IA`
   — `docs/prompts/08d-piv-relation-managers.md` + `DESIGN.md`.
2. `feat(models): add Piv::asignaciones HasManyThrough via averias`
   — `app/Models/Piv.php`.
3. `feat(filament): add Averias + Asignaciones RelationManagers to PivResource`
   — los 2 RelationManagers + `Pages/ViewPiv.php` + `PivResource.php` (getRelations + getPages + ViewAction).
4. `chore(filament): hide Averia + Asignacion resources from sidebar (URL still accessible)`
   — `AveriaResource.php` + `AsignacionResource.php` (`$shouldRegisterNavigation = false`).
5. `test: cover RelationManagers + sidebar visibility regression`
   — `tests/Feature/Filament/PivRelationManagersTest.php`.

Push + PR:
```bash
git push -u origin bloque-08d-piv-relation-managers
gh pr create --base main --head bloque-08d-piv-relation-managers \
  --title "Bloque 08d — Refactor IA a parent-child: RelationManagers en PivResource" \
  --body "$(cat <<'BODY'
## Resumen

Refactor de IA: averías y asignaciones se consultan **desde el panel** vía RelationManager tabs, no como entries top-level del sidebar. La app vieja ya usaba tabs (#tabs-3) — patrón validado por años. Bloque 08 inicial las puso peer-level por error.

## Cambios

- Piv::asignaciones() HasManyThrough via averias (asignacion no tiene piv_id directo).
- 2 RelationManagers en PivResource: AveriasRelationManager + AsignacionesRelationManager. Mismo patrón Airtable-Mode (Bloque 07d) con stripe lateral por tipo (regla #11) en asignaciones.
- ViewPiv.php page nuevo. PivResource ViewAction navega a esta page (sin slideOver) — peek + drill-in en una sola página.
- AveriaResource + AsignacionResource: \`shouldRegisterNavigation = false\`. URL accesible para Bloque 10 reportes cross-panel.
- DESIGN.md §10.4 documenta el patrón parent-child.
- 7 tests nuevos.

## Verificación post-merge

- /admin/pivs → click panel → View page con tabs Averías + Asignaciones del panel solamente.
- Sidebar primario: solo "Activos > Paneles PIV" (Operaciones group desaparece).
- /admin/averias y /admin/asignaciones siguen accesibles vía URL directa (regression test).

## CI esperado

3/3 verde.
BODY
)"

sleep 8
PR_NUM=$(gh pr list --head bloque-08d-piv-relation-managers --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ Piv::asignaciones HasManyThrough.
✅ AveriasRelationManager + AsignacionesRelationManager con Airtable-Mode + stripe lateral.
✅ ViewPiv page con tabs.
✅ AveriaResource + AsignacionResource fuera de sidebar (URL accesible).
✅ DESIGN.md §10.4 nuevo + entry log 2026-05-02.
✅ 7 tests nuevos.
✅ 127 tests verdes. Pint + build OK.
✅ PR #N. CI 3/3 verde.
```

NO mergees.

END PROMPT
```
