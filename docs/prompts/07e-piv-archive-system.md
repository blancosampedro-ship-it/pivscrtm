# Bloque 07e — Sistema de archivado de filas piv (ADR-0012)

> **Cómo se usa:** copia el bloque `BEGIN PROMPT` … `END PROMPT` y pégalo en VS Code Copilot Chat (modo Agent). ~75-90 min.

---

## Objetivo

Resolver la contaminación detectada en auditoría 1-may-2026: la tabla legacy `piv` contiene **~91-101 filas que NO son paneles** sino vehículos de un proyecto antiguo (Soler i Sauret, Sarbus, Monbus, Autos Castellbisbal, Font, Autocorb, Mohn, Rosanbus, Tusgsal — todos en piv_id 469-559).

**Solución arquitectónica**: sistema de archivado **soft-delete reversible** vía nueva tabla `lv_piv_archived`. La tabla legacy `piv` no se modifica (regla #1, regla #2). PivResource aplica scope `notArchived()` por defecto. Filter "Archivados" alterna entre activos / archivados / todos. Action archive/unarchive en cada fila + bulk action.

**Por qué soft-delete y no DELETE prod legacy:**
- Reversible — si en 6 meses descubres que necesitabas un bus archivado, lo restauras.
- App vieja sigue viendo todos los registros (coexistencia preservada).
- Audit trail completo (quién, cuándo, por qué).
- Cumple regla #2 (no DML legacy) — solo INSERT en tabla `lv_*` nueva.
- Tras cutover Fase 7, se puede convertir a hard-delete real en una pasada controlada.

## Definition of Done

1. Migration `lv_piv_archived` con schema según ADR-0012.
2. Model `App\Models\LvPivArchived` + factory.
3. Model `App\Models\Piv`:
   - Relación `archive()` HasOne LvPivArchived.
   - Método `isArchived(): bool`.
   - Scope `notArchived()` (default usado en Resource).
   - Scope `onlyArchived()`.
4. ADR-0012 nuevo en `docs/decisions/0012-piv-archive-strategy.md`.
5. `App\Filament\Resources\PivResource`:
   - `getEloquentQuery()` aplica `notArchived()` por defecto.
   - `TernaryFilter::make('archived')` con 3 estados (Activos / Archivados / Todos).
   - `Tables\Actions\Action::make('archive')` con modal de razón (textarea opcional). Crea fila en `lv_piv_archived`. Visible solo en filas no-archivadas.
   - `Tables\Actions\Action::make('unarchive')` con confirmación. Borra fila de `lv_piv_archived`. Visible solo en archivadas.
   - `Tables\Actions\BulkAction::make('archiveSelected')` con modal de razón.
   - Visual: filas archivadas con `extraAttributes` opacity 0.6 cuando se muestran (filter Archivados/Todos).
6. Tests Pest:
   - Migration crea las columnas correctas.
   - `Piv::archive()` relación funciona.
   - `Piv::scopeNotArchived()` excluye archivadas.
   - `Piv::scopeOnlyArchived()` solo archivadas.
   - `archive_action_creates_lv_piv_archived_row` (Livewire).
   - `unarchive_action_deletes_lv_piv_archived_row` (Livewire).
   - `bulk_archive_inserts_multiple_rows` (Livewire).
   - `archived_pivs_excluded_from_default_listing` (Livewire).
   - `filter_archived_shows_only_archived` (Livewire).
   - `pivs_index_query_count_unchanged_with_archive_relation` (eager-load no rompe N+1 test del Bloque 07).
7. Runbook `docs/runbooks/07e-bulk-archive-bus-rows.md` con pasos para archivar masivamente las ~101 filas-bus (post-merge).
8. `pint --test`, `pest`, `npm run build` verdes.
9. PR creado, CI 3/3 verde.
10. **Post-merge**: ejecutar runbook → bulk-archive 101 buses → smoke `/admin/pivs` muestra solo paneles reales.

---

## Riesgos y mitigaciones

- **N+1 con `whereDoesntHave('archive')`**: Eloquent compila esto a NOT EXISTS subquery — single query, sin N+1. Test `pivs_listing_no_n_plus_one` debe seguir verde.
- **`archived_by_user_id` puede ser null** si el archive es por seeder/script (no usuario). Schema lo permite.
- **Conflicto con `Piv::scopeForOperador`**: ambos scopes pueden combinarse (`Piv::forOperador($id)->notArchived()`). Test cubre.
- **`UNIQUE KEY uniq_piv (piv_id)`** evita doble-archivado accidental. Si admin click 2x, el segundo intento da error gracioso.
- **Filter por defecto excluye archivados**: si admin hace búsqueda y no encuentra una fila, puede ser que esté archivada. UI debe ser clara — mostrar conteo de archivados en alguna parte.
- **Test `pivs_listing_no_n_plus_one` (Bloque 07)**: actualmente espera ≤8 queries. Con `whereDoesntHave` añade 1 subquery. Subir el límite a ≤9. NO un fallo real, ajuste cosmético.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md
- CLAUDE.md
- docs/decisions/0002-database-coexistence.md (lv_* tables pattern)
- docs/prompts/07e-piv-archive-system.md (este archivo)
- app/Models/Piv.php (modelo actual con thumbnail accessor del Bloque 07d)
- app/Filament/Resources/PivResource.php (resource actual con slideOver inspector del Bloque 07d)

Tu tarea: implementar Bloque 07e — sistema de archivado soft-delete para filas piv.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch

```bash
pwd
git branch --show-current        # main
git rev-parse HEAD               # debe ser 76c98c0 (post Bloque 07d)
git status --short               # vacío
./vendor/bin/pest --colors=never --compact 2>&1 | tail -3
```

100 tests verdes esperados.

```bash
git checkout -b bloque-07e-piv-archive-system
```

PARA: "Branch creada. ¿Procedo a Fase 1 (ADR-0012)?"

## FASE 1 — Escribir ADR-0012

Crea `docs/decisions/0012-piv-archive-strategy.md`:

```markdown
# 0012 — Sistema de archivado para filas `piv`

- **Status**: Accepted
- **Date**: 2026-05-01
- **Tipo**: Pattern arquitectónico, no afecta auth ni schema legacy.

## Context

Auditoría exhaustiva de la tabla `piv` (1-may-2026) reveló contaminación: ~91-101 filas en piv_id 469-559 no son paneles informativos sino registros de vehículos de un proyecto antiguo donde el usuario reusó la BD para gestión de autobuses. Operadores identificados: Soler i Sauret, Sarbus, Monbus, Autos Castellbisbal, Font, Autocorb, Mohn, Rosanbus, Tusgsal (todos catalanes). Características visuales:

| Campo | Panel real | Bus contaminante |
|---|---|---|
| `parada_cod` | numérico ("06036") | "Soler i Sauret 103" (texto libre) |
| `direccion` | rellena | vacía "" |
| `municipio` | id de modulo tipo=5 | "0" (centinela "sin asignar") |
| `industria_id`, `operador_id` | apuntan a registros válidos | NULL |

Adicionalmente, hay ~115 filas "dudosas" (parada_cod no numérico pero con dirección/municipio rellenos): hospitales, intercambiadores, terminales, variantes de panel con sufijo letrado (06692A/B/C). MAYORÍA son paneles reales con nomenclatura especial — NO contaminantes.

Necesidad: el admin (Filament `/admin/pivs`) debe poder ocultar las filas-bus sin destruirlas, con capacidad de restaurar si fuese necesario.

## Decision

**Soft-archive vía nueva tabla `lv_piv_archived`.**

### Schema

```sql
CREATE TABLE lv_piv_archived (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    piv_id              INT NOT NULL,                          -- FK lógica a piv.piv_id
    archived_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_by_user_id BIGINT UNSIGNED NULL,                  -- FK lógica a lv_users.id
    reason              VARCHAR(255) NULL,
    created_at          TIMESTAMP NULL,
    updated_at          TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_piv_archived (piv_id),
    KEY idx_archived_at (archived_at),
    KEY idx_archived_by (archived_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`UNIQUE KEY uniq_piv_archived` evita doble-archivado accidental. Sin FK física a `piv` (regla coexistencia ADR-0002) ni a `lv_users` (consistencia con resto de tablas lv_*).

### Comportamiento

1. **Por defecto, listing oculta archivados**: `Piv::query()->notArchived()` (scope) excluye via `whereDoesntHave('archive')`. PivResource lo aplica en `getEloquentQuery()`.
2. **Filter UI** con 3 estados:
   - `Activos` (default, blank) → solo no-archivados
   - `Archivados` (true) → solo archivados
   - `Todos` (false) → ambos, con visual indicator (opacity 0.6) en archivados
3. **Action archive** abre modal con campo `reason` (textarea opcional). Inserta fila en `lv_piv_archived` con `archived_by_user_id = auth()->id()`.
4. **Action unarchive** (visible solo en archivadas) confirma + borra fila de `lv_piv_archived`.
5. **Bulk archive**: selección múltiple + reason → batch insert.
6. **App vieja sin afectar**: la tabla `piv` no se modifica. `winfin.es/paneles.php` sigue mostrando los 575 registros incluyendo los archivados — coexistencia preservada (regla #1).

### Por qué soft-archive y no DELETE legacy

- **Reversible**: descubrimientos tardíos (un bus archivado tenía datos de reporte histórico) recuperables con un click.
- **Audit trail**: quién/cuándo/por qué — útil cuando alguien pregunta "¿dónde está el panel X?".
- **Sin DML legacy**: regla #2 sin invocar — solo INSERT en `lv_*` nueva.
- **Coexistencia**: app vieja no rompe.
- **Migración futura**: tras cutover Fase 7, hard-delete real es trivial (`DELETE FROM piv WHERE piv_id IN (SELECT piv_id FROM lv_piv_archived)`).

### Bulk one-shot post-merge

Tras mergear este bloque, runbook `docs/runbooks/07e-bulk-archive-bus-rows.md` ejecuta:
1. Backup prod DB.
2. SELECT inventario: piv_ids con parada_cod no-numérico + dir vacía + mun=0.
3. Confirmación humana sobre la lista.
4. INSERT batch en `lv_piv_archived` con `reason="Bus row from legacy vehicle project — bulk archive 2026-05-01 (audit ADR-0012)"`.
5. Smoke verificación count.

## Considered alternatives

- **DELETE inmediato de las filas-bus en `piv`** — descartado: viola spirit de regla #2 (DML masivo en legacy), irreversible sin restore de backup, app vieja podría tener referencias/reportes que dependen de esos IDs.
- **Migrar las filas-bus a `lv_vehiculos_legacy`** y hard-delete de `piv` — descartado: doble trabajo (mover + borrar), pierde la coexistencia, requiere ADR adicional para schema vehículos.
- **Filtro estático por heurística (parada_cod regex)** sin tabla — descartado: heurística falla en edge cases (rows dudosas) + no permite admin marcar manualmente nuevos buses si aparecen.
- **Soft-delete column en piv (modificar legacy schema)** — descartado: viola regla #2.
- **`whereNotIn(piv_id, [list_hardcoded])`** — descartado: lista crece sin control, sin audit, sin reverse.

## Consequences

**Positivas:**
- Tabla legacy intacta. App vieja sin afectar.
- Listing admin limpio sin tocar BD.
- Audit trail completo. RGPD-friendly.
- Reversible en cualquier momento.
- Patrón reutilizable para futuras tablas legacy con contaminación similar (averia, asignacion, etc).

**Negativas:**
- Una subquery extra (`whereDoesntHave`) en cada listing del PivResource. Cost: ~1ms en MySQL con índice `uniq_piv_archived`. Insignificante.
- Admin debe entender el concepto "archivado" — UI con filter de 3 estados + counter de archivados ayuda.
- Las filas archivadas siguen visibles en app vieja (esperado, pero confuso si admin las re-mira ahí). Aceptable hasta cutover.

**Implementación**: ver Bloque 07e.
```

PARA: "Fase 1 completa: ADR-0012 escrito. ¿Procedo a Fase 2 (migration)?"

## FASE 2 — Migration `lv_piv_archived`

Crea `database/migrations/2026_05_01_120000_create_lv_piv_archived_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla nueva para registrar paneles "archivados" (soft-delete reversible).
 *
 * Schema según ADR-0012. Sin FK física a `piv` ni a `lv_users` (regla
 * coexistencia ADR-0002 + consistencia con resto de tablas lv_*).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_piv_archived', function (Blueprint $t) {
            $t->id();
            $t->integer('piv_id');                                  // FK lógica a piv.piv_id
            $t->timestamp('archived_at')->useCurrent();
            $t->unsignedBigInteger('archived_by_user_id')->nullable(); // FK lógica a lv_users.id
            $t->string('reason', 255)->nullable();
            $t->timestamps();

            $t->unique('piv_id', 'uniq_piv_archived');
            $t->index('archived_at', 'idx_archived_at');
            $t->index('archived_by_user_id', 'idx_archived_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_piv_archived');
    }
};
```

PARA: "Fase 2 completa: migration creada. ¿Procedo a Fase 3 (LvPivArchived model + factory)?"

## FASE 3 — Model + factory `LvPivArchived`

### 3a — `app/Models/LvPivArchived.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de archivado para una fila `piv` (ADR-0012).
 *
 * Inserta una fila aquí = panel ocultado del admin Filament por defecto.
 * Borra una fila aquí = panel restaurado (visible de nuevo).
 *
 * Sin FK física a `piv` (regla ADR-0002). La integridad la valida la app —
 * en práctica, `uniq_piv_archived` evita duplicados.
 */
class LvPivArchived extends Model
{
    use HasFactory;

    protected $table = 'lv_piv_archived';

    protected $fillable = [
        'piv_id',
        'archived_at',
        'archived_by_user_id',
        'reason',
    ];

    protected $casts = [
        'piv_id' => 'integer',
        'archived_at' => 'datetime',
        'archived_by_user_id' => 'integer',
    ];

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id', 'id');
    }
}
```

### 3b — `database/factories/LvPivArchivedFactory.php`

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LvPivArchived;
use Illuminate\Database\Eloquent\Factories\Factory;

class LvPivArchivedFactory extends Factory
{
    protected $model = LvPivArchived::class;

    public function definition(): array
    {
        return [
            'piv_id' => $this->faker->unique()->numberBetween(1, 999999),
            'archived_at' => now(),
            'archived_by_user_id' => null,
            'reason' => 'Auto-generated factory archive',
        ];
    }
}
```

PARA: "Fase 3 completa: LvPivArchived modelo + factory. ¿Procedo a Fase 4 (Piv model relación + scopes)?"

## FASE 4 — Update `Piv` model: relación `archive` + scopes

Lee `app/Models/Piv.php`. Localiza el último método (probablemente `getThumbnailUrlAttribute` o `scopeForOperador`) y añade DESPUÉS:

```php
    /**
     * Archivado del panel (ADR-0012). Si existe fila en `lv_piv_archived`,
     * el panel está oculto por defecto del listing admin.
     */
    public function archive(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LvPivArchived::class, 'piv_id', 'piv_id');
    }

    public function isArchived(): bool
    {
        return $this->archive()->exists();
    }

    /**
     * Scope: solo paneles NO archivados (default en listing admin).
     */
    public function scopeNotArchived(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereDoesntHave('archive');
    }

    /**
     * Scope: solo paneles archivados (filter "Archivados" en admin).
     */
    public function scopeOnlyArchived(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereHas('archive');
    }
```

Asegúrate de que `LvPivArchived` está en el `use` block del top del archivo.

PARA: "Fase 4 completa: Piv tiene archive() relación + scopes. ¿Procedo a Fase 5 (PivResource updates)?"

## FASE 5 — Update `PivResource`

Cambios en `app/Filament/Resources/PivResource.php`:

### 5a — `getEloquentQuery()` aplica `notArchived()` por defecto

Localiza el método y modifica:

```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->notArchived()  // <-- AÑADIR ESTA LÍNEA
        ->with([
            'operadorPrincipal:operador_id,razon_social',
            'industria:modulo_id,nombre',
            'municipioModulo:modulo_id,nombre',
            'imagenes',
            'archive',  // <-- AÑADIR ESTA RELACIÓN al with para evitar N+1 en isArchived()
        ]);
}
```

### 5b — Filter ternario "Archivados"

En el método `table()`, añade al array `->filters([])`:

```php
Tables\Filters\TernaryFilter::make('archived')
    ->label('Estado')
    ->placeholder('Activos')
    ->trueLabel('Solo archivados')
    ->falseLabel('Todos (incluye archivados)')
    ->queries(
        true: fn (Builder $q) => $q->withoutGlobalScopes()->onlyArchived(),
        false: fn (Builder $q) => $q->withoutGlobalScopes(),
        blank: fn (Builder $q) => $q,  // default — getEloquentQuery ya aplicó notArchived
    ),
```

NOTA: `getEloquentQuery()` ya filtró `notArchived()`, así que el `blank` no necesita re-aplicar. Para `true` y `false` necesitamos REVERTIR el filter por defecto (no hay un scope nuestro para "ignorar el filter del query base"; mejor: en lugar de `withoutGlobalScopes`, sobreescribir la query base).

**Alternativa más robusta**: cambiar `getEloquentQuery()` para NO aplicar `notArchived()` por defecto, y poner el filter como Activos por defecto:

```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->with([...]);  // sin notArchived() aquí
}
```

Y en el filter:
```php
Tables\Filters\TernaryFilter::make('archived')
    ->label('Estado')
    ->placeholder('Activos')
    ->trueLabel('Solo archivados')
    ->falseLabel('Todos')
    ->queries(
        true: fn (Builder $q) => $q->onlyArchived(),
        false: fn (Builder $q) => $q,  // todos
        blank: fn (Builder $q) => $q->notArchived(),  // default = activos
    )
    ->default(),  // visualmente activo por default
```

Aplica esta segunda variante (más limpia). Verifica con tinker que funciona.

### 5c — Action archive (single)

Añade al array `->actions([])`:

```php
Tables\Actions\Action::make('archive')
    ->label('Archivar')
    ->icon('heroicon-o-archive-box-arrow-down')
    ->iconButton()
    ->color('warning')
    ->visible(fn (Piv $record) => ! $record->isArchived())
    ->requiresConfirmation()
    ->modalHeading('Archivar panel')
    ->modalDescription('El panel quedará oculto del listado admin. Reversible.')
    ->modalSubmitActionLabel('Archivar')
    ->form([
        Forms\Components\Textarea::make('reason')
            ->label('Razón (opcional)')
            ->placeholder('Ej.: bus contaminante de proyecto antiguo')
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
            ->body("PIV #{$record->piv_id} ya no aparece en el listing por defecto.")
            ->success()
            ->send();
    }),
```

### 5d — Action unarchive (single)

Añade al array `->actions([])`:

```php
Tables\Actions\Action::make('unarchive')
    ->label('Restaurar')
    ->icon('heroicon-o-arrow-uturn-up')
    ->iconButton()
    ->color('success')
    ->visible(fn (Piv $record) => $record->isArchived())
    ->requiresConfirmation()
    ->modalHeading('Restaurar panel')
    ->modalDescription('Volverá a aparecer en el listing por defecto.')
    ->action(function (Piv $record) {
        \App\Models\LvPivArchived::where('piv_id', $record->piv_id)->delete();

        \Filament\Notifications\Notification::make()
            ->title('Panel restaurado')
            ->body("PIV #{$record->piv_id} vuelve a estar activo.")
            ->success()
            ->send();
    }),
```

### 5e — Bulk action archiveSelected

Añade `->bulkActions([])` al table:

```php
->bulkActions([
    Tables\Actions\BulkAction::make('archiveSelected')
        ->label('Archivar seleccionados')
        ->icon('heroicon-o-archive-box-arrow-down')
        ->color('warning')
        ->requiresConfirmation()
        ->modalHeading('Archivar paneles seleccionados')
        ->form([
            Forms\Components\Textarea::make('reason')
                ->label('Razón')
                ->placeholder('Ej.: bus rows from legacy vehicle project')
                ->required()
                ->rows(2)
                ->maxLength(255),
        ])
        ->action(function ($records, array $data) {
            $count = 0;
            foreach ($records as $piv) {
                if (! $piv->isArchived()) {
                    \App\Models\LvPivArchived::create([
                        'piv_id' => $piv->piv_id,
                        'archived_at' => now(),
                        'archived_by_user_id' => auth()->id(),
                        'reason' => $data['reason'],
                    ]);
                    $count++;
                }
            }

            \Filament\Notifications\Notification::make()
                ->title("{$count} paneles archivados")
                ->success()
                ->send();
        })
        ->deselectRecordsAfterCompletion(),
]),
```

### 5f — Visual indicator: filas archivadas con opacity

En el `->columns([])`, añade una row class custom:

Cambia el método table para incluir `->recordClasses(...)`:

```php
return $table
    ->striped()
    ->paginated([25, 50, 100])
    ->defaultPaginationPageOption(25)
    ->recordClasses(fn (Piv $record) => $record->isArchived() ? 'opacity-60' : null)
    ->columns([...])
    // ... resto igual
```

PARA: "Fase 5 completa: Resource con filter + archive/unarchive actions + bulk + visual indicator. ¿Procedo a Fase 6 (tests)?"

## FASE 6 — Tests

### 6a — Migration test

Añade a `tests/Feature/Migrations/LvTablesTest.php` (creado en Bloque 04):

```php
it('creates lv_piv_archived table with correct columns', function () {
    foreach (['id', 'piv_id', 'archived_at', 'archived_by_user_id', 'reason', 'created_at', 'updated_at'] as $col) {
        expect(Schema::hasColumn('lv_piv_archived', $col))->toBeTrue("Falta columna {$col}");
    }
});
```

### 6b — Test relación + scopes en Piv

Crea `tests/Feature/Models/PivArchiveTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\LvPivArchived;
use App\Models\Modulo;
use App\Models\Piv;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('isArchived returns false when no archive row', function () {
    $piv = Piv::factory()->create(['piv_id' => 99001]);
    expect($piv->isArchived())->toBeFalse();
});

it('isArchived returns true when archive row exists', function () {
    $piv = Piv::factory()->create(['piv_id' => 99002]);
    LvPivArchived::create(['piv_id' => 99002, 'archived_at' => now()]);
    expect($piv->fresh()->isArchived())->toBeTrue();
});

it('scope notArchived excludes archived', function () {
    Piv::factory()->create(['piv_id' => 99003]);
    Piv::factory()->create(['piv_id' => 99004]);
    LvPivArchived::create(['piv_id' => 99004, 'archived_at' => now()]);

    $ids = Piv::notArchived()->pluck('piv_id')->all();
    expect($ids)->toContain(99003)->not->toContain(99004);
});

it('scope onlyArchived returns only archived', function () {
    Piv::factory()->create(['piv_id' => 99005]);
    Piv::factory()->create(['piv_id' => 99006]);
    LvPivArchived::create(['piv_id' => 99006, 'archived_at' => now()]);

    $ids = Piv::onlyArchived()->pluck('piv_id')->all();
    expect($ids)->toContain(99006)->not->toContain(99005);
});

it('uniq_piv_archived prevents double archive', function () {
    Piv::factory()->create(['piv_id' => 99007]);
    LvPivArchived::create(['piv_id' => 99007, 'archived_at' => now()]);

    expect(fn () => LvPivArchived::create(['piv_id' => 99007, 'archived_at' => now()]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
```

### 6c — Tests de Resource (acciones Filament)

Añade a `tests/Feature/Filament/PivResourceTest.php`:

```php
it('archive_action_creates_lv_piv_archived_row', function () {
    $piv = Piv::factory()->create(['piv_id' => 88001]);

    Livewire::test(ListPivs::class)
        ->callTableAction('archive', $piv->piv_id, data: ['reason' => 'test archive']);

    expect(\App\Models\LvPivArchived::where('piv_id', 88001)->exists())->toBeTrue();
    expect(\App\Models\LvPivArchived::where('piv_id', 88001)->first()->reason)->toBe('test archive');
});

it('unarchive_action_deletes_lv_piv_archived_row', function () {
    $piv = Piv::factory()->create(['piv_id' => 88002]);
    \App\Models\LvPivArchived::create(['piv_id' => 88002, 'archived_at' => now()]);

    // El record debe estar en el filter "todos" o "archivados" para que sea visible.
    Livewire::test(ListPivs::class)
        ->filterTable('archived', true)  // mostrar archivados
        ->callTableAction('unarchive', $piv->piv_id);

    expect(\App\Models\LvPivArchived::where('piv_id', 88002)->exists())->toBeFalse();
});

it('archived_pivs_excluded_from_default_listing', function () {
    $active = Piv::factory()->create(['piv_id' => 88003]);
    $archived = Piv::factory()->create(['piv_id' => 88004]);
    \App\Models\LvPivArchived::create(['piv_id' => 88004, 'archived_at' => now()]);

    Livewire::test(ListPivs::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$archived]);
});

it('filter_archived_shows_only_archived', function () {
    $active = Piv::factory()->create(['piv_id' => 88005]);
    $archived = Piv::factory()->create(['piv_id' => 88006]);
    \App\Models\LvPivArchived::create(['piv_id' => 88006, 'archived_at' => now()]);

    Livewire::test(ListPivs::class)
        ->filterTable('archived', true)
        ->assertCanSeeTableRecords([$archived])
        ->assertCanNotSeeTableRecords([$active]);
});

it('bulk_archive_inserts_multiple_rows', function () {
    $pivs = collect(range(88010, 88014))->map(fn ($id) => Piv::factory()->create(['piv_id' => $id]));

    Livewire::test(ListPivs::class)
        ->callTableBulkAction('archiveSelected', $pivs->pluck('piv_id')->all(), data: ['reason' => 'bulk test']);

    expect(\App\Models\LvPivArchived::whereIn('piv_id', $pivs->pluck('piv_id'))->count())->toBe(5);
});
```

### 6d — Ajustar test N+1 existente

Lee `tests/Feature/Filament/PivResourceTest.php` y localiza el test `piv_listing_no_n_plus_one`. Sube el límite de queries de `8` a `9` (la nueva relación `archive` añade 1 subquery del `whereDoesntHave`):

```php
expect($count)->toBeLessThanOrEqual(9, "Se ejecutaron {$count} queries — eager loading roto");
```

Corre:
```bash
./vendor/bin/pest --colors=never --compact 2>&1 | tail -20
```

Suite total esperada: 100 + 5 + 5 = 110 tests verdes (aproximado). Si rompe alguno, AVISA.

PARA: "Fase 6 completa: 110 tests verdes. ¿Procedo a Fase 7 (runbook + commits + PR)?"

## FASE 7 — Runbook + commits + PR

### 7a — Crear runbook post-merge

Crea `docs/runbooks/07e-bulk-archive-bus-rows.md`:

```markdown
# Runbook 07e — Bulk archive bus rows post-merge

> Se ejecuta DESPUÉS de mergear PR del Bloque 07e. Lo hace Claude Code o el usuario
> vía tinker/script. Toca BD producción (insertando en `lv_piv_archived`, NO toca `piv`).

## Prerequisitos

- PR Bloque 07e mergeado en `main`.
- Local sincronizado (`git pull`).
- Migrate aplicado a prod: `php artisan migrate` (crea `lv_piv_archived` en SiteGround).
- Backup fresco de la BD prod (mysqldump < 24h).

## Pasos

### 1. Backup prod

Mismo patrón que Bloque 05:
```bash
MYSQLDUMP=/usr/local/opt/mysql-client@8.4/bin/mysqldump
TS=$(date +%Y%m%d-%H%M%S)
BACKUP=~/Documents/winfin-piv-backups-locales/prod-pre-bloque-07e-$TS.sql
TMP=$(mktemp); chmod 600 $TMP
DB_HOST=$(grep '^DB_HOST=' .env | cut -d= -f2-)
DB_USER=$(grep '^DB_USERNAME=' .env | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)
DB_NAME=$(grep '^DB_DATABASE=' .env | cut -d= -f2-)
cat > $TMP <<EOF
[client]
host=$DB_HOST
user=$DB_USER
password=$DB_PASS
EOF
$MYSQLDUMP --defaults-extra-file=$TMP --single-transaction --quick --no-tablespaces --skip-lock-tables "$DB_NAME" > $BACKUP
shasum -a 256 $BACKUP
rm $TMP
```

### 2. Aplicar migration a prod

```bash
php artisan migrate --pretend       # ver SQL
# Confirmar: solo CREATE TABLE lv_piv_archived
php artisan migrate
php artisan migrate:status | grep lv_piv_archived
```

### 3. Generar lista de piv_ids candidatos a archivar

```bash
php artisan tinker --execute='
$candidates = \DB::table("piv")
    ->whereRaw("REGEXP_REPLACE(parada_cod, \"[[:space:]]\", \"\") NOT REGEXP \"^[0-9]+[A-Z]?(\\\\([a-zA-Z ]+\\\\))?$\" OR parada_cod IS NULL OR parada_cod = \"\"")
    ->where(function($q){ $q->whereNull("direccion")->orWhere("direccion", ""); })
    ->where(function($q){ $q->whereNull("municipio")->orWhere("municipio", "")->orWhere("municipio", "0"); })
    ->orderBy("piv_id")
    ->select("piv_id", "parada_cod")
    ->get();
echo "TOTAL: " . count($candidates) . PHP_EOL;
foreach ($candidates as $r) echo $r->piv_id . " " . trim($r->parada_cod) . PHP_EOL;
'
```

Guarda la lista en `docs/runbooks/legacy-cleanup/bus-archive-ids-$(date +%Y%m%d).txt` con sha256 al final del archivo. Audit trail.

### 4. Confirmación humana sobre la lista

Revisa los IDs visualmente. Si alguno parece sospechoso (no es bus claro), quítalo del archivo. Esperado: ~91-101 IDs en rango contiguo 469-559.

### 5. Bulk insert en lv_piv_archived

```bash
php artisan tinker --execute='
$ids = file("docs/runbooks/legacy-cleanup/bus-archive-ids-YYYYMMDD.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$ids = array_filter(array_map(fn($l) => (int) explode(" ", $l)[0], $ids));
echo "A archivar: " . count($ids) . PHP_EOL;
$reason = "Bus row from legacy vehicle project — bulk archive 2026-05-01 (audit ADR-0012)";
$inserted = 0;
foreach ($ids as $piv_id) {
    \App\Models\LvPivArchived::create([
        "piv_id" => $piv_id,
        "archived_at" => now(),
        "archived_by_user_id" => 1,  // admin (info@winfin.es)
        "reason" => $reason,
    ]);
    $inserted++;
}
echo "INSERTED: $inserted" . PHP_EOL;
echo "TOTAL en lv_piv_archived: " . \App\Models\LvPivArchived::count() . PHP_EOL;
'
```

### 6. Smoke verificación

```bash
php artisan tinker --execute='
echo "Paneles activos (default scope): " . \App\Models\Piv::notArchived()->count() . PHP_EOL;
echo "Archivados: " . \App\Models\Piv::onlyArchived()->count() . PHP_EOL;
echo "Total piv tabla legacy: " . \DB::table("piv")->count() . " (sin cambios)" . PHP_EOL;
'
```

Esperado:
- Activos: ~474 (575 - ~101)
- Archivados: ~101
- Total piv: 575 (sin cambios — solo añadimos al lv_piv_archived)

### 7. Smoke navegador

`php artisan serve` → `/admin/pivs` → la lista ya no muestra Soler i Sauret, Sarbus, Monbus, etc. Filter "Solo archivados" los muestra todos.

### 8. Documentar resultado

Apunta en este runbook:
- Fecha ejecución.
- Hash del backup pre-deploy.
- Cuenta exacta de filas archivadas.
- piv_ids inesperados que quedaron sin archivar (revisar manual).
```

### 7b — Commits + push + PR

Stage explícito por archivo:

1. `docs: add Bloque 07e prompt + ADR-0012 + runbook` — `docs/prompts/07e-piv-archive-system.md` + `docs/decisions/0012-piv-archive-strategy.md` + `docs/runbooks/07e-bulk-archive-bus-rows.md`.
2. `feat(migrations): add lv_piv_archived table (ADR-0012)` — la migration.
3. `feat(models): add LvPivArchived model + Piv archive() relation + scopes` — `app/Models/LvPivArchived.php` + `app/Models/Piv.php` + factory.
4. `feat(filament): add archive/unarchive actions + filter to PivResource` — `app/Filament/Resources/PivResource.php`.
5. `test: cover Piv archive scopes + Filament archive actions` — los tests añadidos.

```bash
./vendor/bin/pint --test 2>&1 | tail -3
./vendor/bin/pest --colors=never --compact 2>&1 | tail -5
npm run build 2>&1 | tail -3
git push -u origin bloque-07e-piv-archive-system

gh pr create \
  --base main \
  --head bloque-07e-piv-archive-system \
  --title "Bloque 07e — Sistema de archivado piv (ADR-0012)" \
  --body "$(cat <<'BODY'
## Resumen

Sistema de archivado soft-delete reversible para filas piv contaminantes. Resuelve la contaminación de ~91-101 filas-bus de un proyecto antiguo de vehículos detectada en auditoría 1-may-2026.

**Sin tocar tabla legacy `piv`** — INSERT en nueva tabla `lv_piv_archived`. App vieja sigue viéndolas. Reversible. Audit trail completo.

ADR-0012 documenta la decisión.

## Cambios

- **Migration**: `lv_piv_archived` (id, piv_id UNIQUE, archived_at, archived_by_user_id, reason).
- **Models**: `LvPivArchived` nuevo + `Piv` con `archive()` HasOne + scopes `notArchived()` / `onlyArchived()` + método `isArchived()`.
- **PivResource**:
  - `getEloquentQuery` aplica filter por defecto vía TernaryFilter `archived` (default = Activos).
  - Action `archive` (warning, archive-box-icon) con modal de razón opcional. Visible solo en activas.
  - Action `unarchive` (success, restore-icon) con confirmación. Visible solo en archivadas.
  - Bulk action `archiveSelected` con razón required.
  - Filas archivadas con `opacity-60` cuando se muestran (filter Archivados/Todos).
- **Tests**: 10+ nuevos (relación, scopes, actions, bulk, filter, uniq constraint).
- **Runbook** post-merge para bulk-archive masivo de los ~101 buses.

## Compatibilidad

- Tabla legacy `piv` sin tocar.
- App vieja `winfin.es/paneles.php` sin afectar — sigue mostrando los 575 registros.
- Tests del Bloque 07 (PivResource) siguen verde con ajuste menor de N+1 limit (8 → 9 queries por subquery archive).

## Post-merge

Ejecutar `docs/runbooks/07e-bulk-archive-bus-rows.md`:
1. Backup prod.
2. `php artisan migrate` (crea `lv_piv_archived` en SiteGround).
3. Bulk insert ~101 filas con reason audit.
4. Smoke `/admin/pivs` muestra solo paneles reales.

## CI esperado

3/3 jobs verde.
BODY
)"

sleep 8
PR_NUM=$(gh pr list --head bloque-07e-piv-archive-system --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ Qué he hecho:
   - ADR-0012 documenta soft-archive strategy.
   - Migration lv_piv_archived (id, piv_id UNIQUE, archived_at, archived_by_user_id, reason).
   - LvPivArchived model + factory.
   - Piv::archive() relation + scopes notArchived/onlyArchived + isArchived().
   - PivResource: TernaryFilter + archive/unarchive/bulk actions + visual indicator.
   - 10+ tests nuevos (relación, scopes, Filament actions, bulk, filter).
   - N+1 test ajustado (8 -> 9 queries por subquery archive).
   - Suite total verde.
   - Pint clean. Build OK.
   - 5 commits Conventional Commits.
   - PR #N: [URL].
   - CI 3/3 verde.
   - Runbook post-merge en docs/runbooks/07e-bulk-archive-bus-rows.md.

⏳ Qué falta:
   - (Post-merge) Ejecutar runbook 07e: backup + migrate prod + bulk-archive ~101 filas-bus.
   - Bloque 07f — Operador + Tecnico Resources (las que faltaban en roadmap).

❓ Qué necesito del usuario:
   - Confirmar PR.
   - Mergear (Rebase and merge).
   - Tras merge, ejecutar runbook con Claude Code.
```

NO mergees el PR.

END PROMPT
```

---

## Después de Bloque 07e

1. **Runbook post-merge** (~15 min con Claude): backup + migrate + bulk-archive de los 101 buses + smoke navegador.
2. **Bloque 07f — Operador + Tecnico Resources** (las que faltaban en el roadmap original — pregunta del usuario "me falta el módulo de operadores").
3. Tras 07f, vuelta al roadmap principal: **Bloque 08 — Resources Averia + Asignacion**.
