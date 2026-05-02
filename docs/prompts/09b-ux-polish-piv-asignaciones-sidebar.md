# Bloque 09b — UX polish PivResource + restaurar Asignaciones en sidebar

> Copia el bloque BEGIN PROMPT … END PROMPT en Copilot. ~45-60 min.

---

## Causa

Smoke real Bloque 09 reveló 4 issues UX en el admin:

1. **Tabla `/admin/pivs` con scroll horizontal forzoso para acciones**: con 8 columnas (ID, parada, dirección, municipio, operador, industria, status, thumbnail) las acciones View/Edit quedan fuera de viewport.
2. **SlideOver inspector con foto + 5 secciones se perdió** en Bloque 08d cuando cambiamos `ViewAction::make()->slideOver()` a `ViewAction::make()` plain (para habilitar tabs RelationManager). El peek rápido del wireframe Airtable-Mode (variante C) que aprobaste se rompió.
3. **ViewPiv full page sin botón "Volver"** explícito. Solo breadcrumb que no es obvio.
4. **AsignacionResource oculto del sidebar** (Bloque 08d, `shouldRegisterNavigation = false`). Decisión correcta para AVERÍAS (trazabilidad investigativa per-panel via tabs) PERO incorrecta para ASIGNACIONES (cola operacional diaria cross-panel). Smoke real confirmó la fricción: usuario no encontró el módulo, tuvo que typear URL.

## Decisión

Restaurar el patrón Airtable-Mode original con discovery de 3 tiers + reincorporar Asignaciones al sidebar:

- **Click row → slideOver peek** (foto + 5 secciones del infolist Bloque 07d).
- **Slide over → "Ver histórico de averías"** → navega a ViewPiv full page con tabs.
- **Slide over → "Editar"** → navega a Edit page.
- **ActionGroup kebab** colapsa todas las acciones (Vista rápida, Ver histórico, Editar, Archivar) en un solo icono compacto siempre visible.
- **ViewPiv header action "← Volver al listado"** explícito.
- **AsignacionResource sidebar visible** bajo grupo "Operaciones" con badge count de status=1 (cola pendiente). AveriaResource sigue oculta (per-panel via tabs).

## Definition of Done

1. **PivResource**:
   - `Tables\Actions\ActionGroup::make([...])` agrupa todas las acciones de fila en kebab menu (siempre visible).
   - Dentro del group: `ViewAction::make()->slideOver()->modalWidth('2xl')->infolist(...)` (Vista rápida).
   - `Tables\Actions\Action::make('viewFull')` con label "Ver histórico de averías" + icon + `->url(fn ($record) => static::getUrl('view', ['record' => $record]))` (navega a ViewPiv).
   - `EditAction::make()` (existente).
   - Acciones archive/unarchive existentes (Bloque 07e) entran al group también.
   - El infolist del slideOver añade un `Action` interno "Ver histórico completo →" que linka a ViewPiv (mismo URL que `viewFull`).
2. **ViewPiv** (`app/Filament/Resources/PivResource/Pages/ViewPiv.php`):
   - `getHeaderActions()` añade primero un `Actions\Action::make('back')->label('Volver al listado')->icon('heroicon-o-arrow-left')->color('gray')->url(...)`.
   - Mantiene EditAction existente.
3. **AsignacionResource**:
   - QUITAR `protected static bool $shouldRegisterNavigation = false`.
   - AÑADIR `protected static ?string $navigationGroup = 'Operaciones'`.
   - AÑADIR `protected static ?int $navigationSort = 1` (primera del grupo Operaciones).
   - AÑADIR `getNavigationBadge()` que retorna count de `status = 1` (asignaciones pendientes), o null si 0.
   - AÑADIR `getNavigationBadgeColor()` retornando "warning" cuando hay pendientes.
4. **Tests Pest** (5+):
   - `piv_resource_uses_action_group_for_row_actions` — confirmar que las acciones están dentro de un ActionGroup.
   - `piv_view_action_uses_slideover` — verificar que ViewAction tiene slideOver activado.
   - `view_piv_page_has_volver_header_action` — header action 'back' presente.
   - `asignacion_resource_shows_in_sidebar` — `shouldRegisterNavigation()` returns true.
   - `asignacion_resource_navigation_badge_shows_open_count` — con 3 status=1 + 5 status=2, badge devuelve "3".
   - `asignacion_resource_navigation_group_is_operaciones` — `$navigationGroup === 'Operaciones'`.
5. **Smoke real obligatorio post-merge**: usuario verifica en navegador que (a) sidebar muestra "Operaciones > Asignaciones [badge]", (b) /admin/pivs tiene kebab menu compacto siempre visible, (c) click row abre slideOver con foto + 5 secciones, (d) ViewPiv tiene botón "Volver al listado".
6. `pint --test`, `pest`, `npm run build` verdes.
7. PR creado, CI 3/3 verde.

---

## Riesgos y mitigaciones (checklist aplicada)

### 1. Compatibility framework

- [x] **`Tables\Actions\ActionGroup`** soportado en Filament 3 estándar. Renderiza como kebab vertical icon.
- [x] **ActionGroup con slideOver inside**: el ViewAction dentro del group puede tener slideOver — Filament resuelve la modal correctamente.
- [x] **Header Action con `->url()`**: Filament 3 estándar, `Actions\Action::make()->url($urlString)`.
- [x] **`getNavigationBadge()`**: método estático heredable de Resource. Retornar string (count) o null. Filament lo invoca al renderizar sidebar.
- [x] **Re-show resource**: borrar `shouldRegisterNavigation = false` o sobreescribir el método explícitamente.

### 2. Inferir de la app vieja

- [x] App vieja tiene "Volver" en cada ficha (validado en capturas previas que mandó usuario, p. ej. `paneles.php?action=edit&id=329`). Patrón establecido.
- [x] Cola de trabajo del admin en app vieja vive en `winfin.es/calendar.php` con vista cross-panel — Asignaciones en sidebar es la equivalencia natural.

### 3. Smoke real obligatorio

- [x] Fase 5 incluye smoke local (curl endpoints + arrancar server).
- [x] REPORTE FINAL pide al usuario verificar 4 escenarios visuales explícitamente (sidebar, kebab, slideOver, Volver).

### 4. Test pivots de Copilot = banderazo rojo

- [x] Si Copilot dice "no puedo testar el sidebar visibility / actionGroup configuration / etc.", AVISA antes de eliminar tests. Tests estáticos via `getNavigationBadge()`, `shouldRegisterNavigation()`, `static::class` checks son perfectamente testeables.

### 5. Datos prod-shaped

- [x] Test del badge usa fixture con varios status (1 + 2 + 0) para verificar count exclusivamente status=1.
- [x] Test de slideOver renderiza con piv que tiene null/0 imágenes para confirmar defensive code (ya cubierto en Bloque 07d/08c, no rebreak).

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md (incluyendo TODOS los patterns 08b/c/d/e/f/g/h y los del Bloque 09).
- DESIGN.md §10.4 (parent-child IA: averías per panel via tab; asignaciones cola operacional cross-panel).
- docs/prompts/09b-ux-polish-piv-asignaciones-sidebar.md (este archivo).
- app/Filament/Resources/PivResource.php (a modificar: ActionGroup + restore slideOver).
- app/Filament/Resources/PivResource/Pages/ViewPiv.php (a modificar: Volver header action).
- app/Filament/Resources/AsignacionResource.php (a modificar: quitar shouldRegisterNavigation = false, añadir badge).

Tu tarea: 4 fixes UX (ActionGroup kebab + slideOver Vista rápida restaurada + Volver button + Asignaciones en sidebar con badge).

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch

```bash
pwd
git branch --show-current        # main
git rev-parse HEAD               # debe ser 721dc77 (post Bloque 09)
git status --short               # vacío
./vendor/bin/pest --colors=never --compact 2>&1 | tail -3
```

135 tests verdes esperados.

```bash
git checkout -b bloque-09b-ux-polish-piv-asignaciones
```

PARA: "Branch creada. ¿Procedo a Fase 1 (PivResource ActionGroup + slideOver)?"

## FASE 1 — PivResource: ActionGroup kebab + restore slideOver Vista rápida

Lee `app/Filament/Resources/PivResource.php`. Localiza el método `table()` y dentro el array `->actions([])`. REEMPLAZA el array entero con:

```php
->actions([
    Tables\Actions\ActionGroup::make([
        Tables\Actions\ViewAction::make()
            ->label('Vista rápida')
            ->icon('heroicon-o-eye')
            ->slideOver()
            ->modalWidth('2xl')
            ->infolist(fn (Infolists\Infolist $infolist) => self::infolist($infolist)),

        Tables\Actions\Action::make('viewFull')
            ->label('Ver histórico de averías')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(fn (Piv $record) => static::getUrl('view', ['record' => $record])),

        Tables\Actions\EditAction::make()
            ->label('Editar'),

        Tables\Actions\Action::make('archive')
            ->label('Archivar')
            ->icon('heroicon-o-archive-box-arrow-down')
            ->color('warning')
            ->visible(fn (Piv $record) => ! $record->isArchived())
            ->requiresConfirmation()
            ->modalHeading('Archivar panel')
            ->modalDescription('El panel quedará oculto del listado admin. Reversible.')
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('Razón (opcional)')
                    ->rows(2)
                    ->maxLength(255),
            ])
            ->action(function (Piv $record, array $data) {
                \App\Models\LvPivArchived::create([
                    'piv_id' => $record->piv_id,
                    'archived_at' => now(),
                    'archived_by_user_id' => auth()->id(),
                    'reason' => $data['reason'] ?? null,
                ]);
                \Filament\Notifications\Notification::make()
                    ->title('Panel archivado')
                    ->success()
                    ->send();
            }),

        Tables\Actions\Action::make('unarchive')
            ->label('Restaurar')
            ->icon('heroicon-o-arrow-uturn-up')
            ->color('success')
            ->visible(fn (Piv $record) => $record->isArchived())
            ->requiresConfirmation()
            ->action(function (Piv $record) {
                \App\Models\LvPivArchived::where('piv_id', $record->piv_id)->delete();
                \Filament\Notifications\Notification::make()
                    ->title('Panel restaurado')
                    ->success()
                    ->send();
            }),
    ])
    ->label('Acciones')
    ->icon('heroicon-m-ellipsis-vertical')
    ->size('sm')
    ->color('gray')
    ->button(),
]),
```

NOTA: si la action archive/unarchive existente está definida en otro sitio (action separada del Bloque 07e), VERIFICA que no se duplique. Si existe en `->actions([Tables\Actions\Action::make('archive')...])` separada, MUEVELA dentro del ActionGroup y borra la separada. Mantén EXACTAMENTE las mismas signatures (visible/action/form) para no romper tests del Bloque 07e.

PARA: "Fase 1 completa: ActionGroup con 5 acciones (Vista rápida, Ver histórico, Editar, Archivar, Restaurar) + slideOver restaurado. ¿Procedo a Fase 2 (ViewPiv Volver button)?"

## FASE 2 — ViewPiv: header action "Volver al listado"

Lee `app/Filament/Resources/PivResource/Pages/ViewPiv.php`. Localiza `getHeaderActions()` y reemplaza:

```php
protected function getHeaderActions(): array
{
    return [
        Actions\Action::make('back')
            ->label('Volver al listado')
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getResource()::getUrl('index')),
        Actions\EditAction::make(),
    ];
}
```

Asegúrate de tener `use Filament\Actions;` al top.

PARA: "Fase 2 completa: ViewPiv con botón Volver. ¿Procedo a Fase 3 (AsignacionResource sidebar)?"

## FASE 3 — AsignacionResource: re-show en sidebar con badge

Lee `app/Filament/Resources/AsignacionResource.php`. Localiza la zona de `protected static` properties.

QUITAR:
```php
protected static bool $shouldRegisterNavigation = false;
```

AÑADIR (si no están ya):
```php
protected static ?string $navigationGroup = 'Operaciones';
protected static ?int $navigationSort = 1;
protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
```

AÑADIR métodos estáticos para el badge:
```php
public static function getNavigationBadge(): ?string
{
    $count = static::getModel()::where('status', 1)->count();
    return $count > 0 ? (string) $count : null;
}

public static function getNavigationBadgeColor(): ?string
{
    return 'warning';
}

public static function getNavigationBadgeTooltip(): ?string
{
    return 'Asignaciones abiertas pendientes de cierre';
}
```

NOTA: AveriaResource MANTIENE `shouldRegisterNavigation = false` — sigue per-panel via tabs (decisión Bloque 08d/g, validada). NO la toques.

PARA: "Fase 3 completa: Asignaciones visible en sidebar con badge. ¿Procedo a Fase 4 (tests)?"

## FASE 4 — Tests

Crea `tests/Feature/Filament/Bloque09bUxTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\AsignacionResource;
use App\Filament\Resources\AveriaResource;
use App\Filament\Resources\PivResource;
use App\Filament\Resources\PivResource\Pages\ViewPiv;
use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Piv;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

// ---------- PivResource ActionGroup + slideOver ----------

it('piv_resource_uses_action_group_for_row_actions', function () {
    // Lectura estática del file: confirma que se usa ActionGroup, no plain ->actions([Action]).
    $source = file_get_contents(app_path('Filament/Resources/PivResource.php'));
    expect($source)->toContain('Tables\\Actions\\ActionGroup::make([');
    expect($source)->toContain("->icon('heroicon-m-ellipsis-vertical')");
});

it('piv_view_action_uses_slideover', function () {
    // Lectura estática: confirma que ViewAction tiene ->slideOver().
    $source = file_get_contents(app_path('Filament/Resources/PivResource.php'));
    expect($source)->toMatch('/ViewAction::make\(\)[\s\S]*?->slideOver\(\)/');
});

it('piv_view_full_action_navigates_to_view_page', function () {
    $source = file_get_contents(app_path('Filament/Resources/PivResource.php'));
    expect($source)->toContain("Action::make('viewFull')");
    expect($source)->toContain("static::getUrl('view'");
});

// ---------- ViewPiv Volver ----------

it('view_piv_page_has_volver_header_action', function () {
    $source = file_get_contents(app_path('Filament/Resources/PivResource/Pages/ViewPiv.php'));
    expect($source)->toContain("Action::make('back')");
    expect($source)->toContain("Volver al listado");
});

// ---------- AsignacionResource sidebar ----------

it('asignacion_resource_shows_in_sidebar', function () {
    expect(AsignacionResource::shouldRegisterNavigation())->toBeTrue();
});

it('asignacion_resource_navigation_group_is_operaciones', function () {
    expect(AsignacionResource::getNavigationGroup())->toBe('Operaciones');
});

it('asignacion_resource_navigation_badge_shows_open_count', function () {
    // Setup: 3 abiertas (status=1) + 5 cerradas (status=2) + 2 raras (status=0).
    $piv = Piv::factory()->create(['piv_id' => 92000]);
    foreach (range(1, 3) as $i) {
        $av = Averia::factory()->create(['averia_id' => 92000 + $i, 'piv_id' => 92000]);
        Asignacion::factory()->create(['asignacion_id' => 92000 + $i, 'averia_id' => 92000 + $i, 'status' => 1]);
    }
    foreach (range(4, 8) as $i) {
        $av = Averia::factory()->create(['averia_id' => 92000 + $i, 'piv_id' => 92000]);
        Asignacion::factory()->create(['asignacion_id' => 92000 + $i, 'averia_id' => 92000 + $i, 'status' => 2]);
    }

    expect(AsignacionResource::getNavigationBadge())->toBe('3');
});

it('asignacion_resource_badge_returns_null_when_no_open', function () {
    // Sin asignaciones status=1 → badge null (no se muestra).
    expect(AsignacionResource::getNavigationBadge())->toBeNull();
});

// ---------- AveriaResource sigue oculto (regression) ----------

it('averia_resource_remains_hidden_from_sidebar', function () {
    expect(AveriaResource::shouldRegisterNavigation())->toBeFalse();
});
```

Corre tests:
```bash
./vendor/bin/pest tests/Feature/Filament/Bloque09bUxTest.php --colors=never --compact 2>&1 | tail -15
./vendor/bin/pest --colors=never --compact 2>&1 | tail -5
```

8 tests nuevos + 135 existentes = 143 verdes esperados. Si rompe alguno del Bloque 07e archive (porque movemos las actions al ActionGroup), AVISA — los signatures deben coincidir exactos.

PARA: "Fase 4 completa: 8 tests verdes + suite total intacta. ¿Procedo a Fase 5 (smoke local + commits + PR)?"

## FASE 5 — Smoke local + commits + PR

```bash
./vendor/bin/pint --test 2>&1 | tail -3
./vendor/bin/pest --colors=never --compact 2>&1 | tail -5
npm run build 2>&1 | tail -3

# Smoke endpoints
php artisan serve --host=127.0.0.1 --port=8001 &
SERVER_PID=$!
sleep 2

curl -sI -o /dev/null -w "GET /admin/pivs        -> HTTP %{http_code}\n" http://127.0.0.1:8001/admin/pivs
curl -sI -o /dev/null -w "GET /admin/pivs/4      -> HTTP %{http_code}\n" http://127.0.0.1:8001/admin/pivs/4
curl -sI -o /dev/null -w "GET /admin/asignaciones -> HTTP %{http_code}\n" http://127.0.0.1:8001/admin/asignaciones

kill $SERVER_PID 2>/dev/null
```

302 esperado en los 3 (redirect a login sin sesión).

Stage explícito:

1. `docs: add Bloque 09b prompt (UX polish PivResource + asignaciones sidebar)` — `docs/prompts/09b-ux-polish-piv-asignaciones-sidebar.md`.
2. `feat(filament): add ActionGroup kebab + restore slideOver in PivResource` — `app/Filament/Resources/PivResource.php`.
3. `feat(filament): add Volver header action in ViewPiv page` — `app/Filament/Resources/PivResource/Pages/ViewPiv.php`.
4. `feat(filament): re-show AsignacionResource in sidebar with open-count badge` — `app/Filament/Resources/AsignacionResource.php`.
5. `test: cover Bloque 09b UX changes` — `tests/Feature/Filament/Bloque09bUxTest.php`.

Push + PR:

```bash
git push -u origin bloque-09b-ux-polish-piv-asignaciones
gh pr create --base main --head bloque-09b-ux-polish-piv-asignaciones \
  --title "Bloque 09b — UX polish PivResource + Asignaciones sidebar" \
  --body "$(cat <<'BODY'
## Resumen

4 fixes UX detectados en smoke real Bloque 09:

1. **ActionGroup kebab** en PivResource: las 5 acciones (Vista rápida, Ver histórico, Editar, Archivar, Restaurar) en un solo icono compacto. Solución a actions fuera de viewport con tabla ancha.
2. **slideOver Vista rápida restaurado**: peek con foto + 5 secciones (Bloque 07d wireframe variante C). Se había perdido en Bloque 08d cuando ViewAction pasó a navegar a View page.
3. **Volver button en ViewPiv** header: navegación back explícita al listado.
4. **AsignacionResource visible en sidebar** bajo grupo "Operaciones" con badge count de status=1. Decisión 08d revisada: averías OK per-panel (trazabilidad), asignaciones requieren acceso cross-panel (cola operacional). AveriaResource sigue oculta.

## 3 tiers discovery (Airtable-Mode pattern recuperado)

- Click row → slideOver peek (Vista rápida).
- Slide over → "Ver histórico de averías →" → ViewPiv full page con tabs.
- Slide over → "Editar" → Edit page.

## Tests

8 tests nuevos:
- piv_resource_uses_action_group_for_row_actions
- piv_view_action_uses_slideover
- piv_view_full_action_navigates_to_view_page
- view_piv_page_has_volver_header_action
- asignacion_resource_shows_in_sidebar
- asignacion_resource_navigation_group_is_operaciones
- asignacion_resource_navigation_badge_shows_open_count
- asignacion_resource_badge_returns_null_when_no_open
- averia_resource_remains_hidden_from_sidebar (regression)

## Smoke real obligatorio post-merge

Verificar en navegador:
1. Sidebar muestra "Operaciones > Asignaciones" (sin badge porque 0 abiertas en prod ahora).
2. /admin/pivs tiene kebab vertical icon en lugar de actions sueltas.
3. Click row → slideOver con foto + 5 secciones.
4. SlideOver footer → "Ver histórico de averías →" → navega a ViewPiv con tabs.
5. ViewPiv tiene "Volver al listado" en header.

## CI esperado

3/3 verde.
BODY
)"

sleep 8
PR_NUM=$(gh pr list --head bloque-09b-ux-polish-piv-asignaciones --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ ActionGroup kebab en PivResource (5 actions: Vista rápida + Ver histórico + Editar + Archivar/Restaurar).
✅ ViewAction restaurado a slideOver con infolist Bloque 07d.
✅ ViewPiv header con Volver button.
✅ AsignacionResource visible en sidebar bajo Operaciones con badge count status=1.
✅ AveriaResource sigue oculta (per-panel pattern preservado).
✅ 8 tests nuevos + 135 existentes verdes.
✅ Pint + build OK. PR #N. CI 3/3 verde.

⏳ Smoke real obligatorio post-merge:
   1. /admin: sidebar con Operaciones > Asignaciones (sin badge si 0 abiertas).
   2. /admin/pivs: kebab vertical icon visible siempre.
   3. Click row -> slideOver peek con foto + 5 secciones.
   4. Slide over -> "Ver histórico" -> ViewPiv con tabs.
   5. ViewPiv -> Volver button funciona.
```

NO mergees.

END PROMPT
```
