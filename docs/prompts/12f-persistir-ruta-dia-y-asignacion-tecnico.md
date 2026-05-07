# Bloque 12f — Persistir ruta del día (`lv_ruta_dia` + `lv_ruta_dia_item`) + asignación técnico

## Contexto

Bloques 12c (rutas oficiales WINFIN) + 12d (CSV SGIP/ICCA) + 12e (Planificador del día read-only) entregan el **cálculo del día**. Bloque 12f **materializa el cálculo como entidad persistente** asignada a un técnico concreto.

Flujo después de 12f:
1. Admin abre `/admin/planificador-dia` (12e) → ve propuesta cruzada de averías ICCA + preventivos + carry overs agrupados por ruta WINFIN.
2. Admin click **"Crear ruta del día"** → modal selector técnico → crea `lv_ruta_dia` + N `lv_ruta_dia_item` (snapshot de 12e).
3. Admin redirigido a `/admin/rutas-dia/{id}/edit` para reordenar / añadir / quitar items.
4. Bloque 12g (futuro) PWA técnico ejecuta la ruta panel a panel.
5. Bloque 12h (futuro) admin cierra averías ICCA + deriva.

## Decisiones cerradas con el usuario antes del prompt

**5 puntos aprobados**:

1. **Schema** `lv_ruta_dia` (cabecera) + `lv_ruta_dia_item` (líneas) con UNIQUE compuesto `(tecnico_id, fecha)`.
2. **Política creación**: snapshot del cálculo 12e — admin click "Crear ruta del día" + modal selector técnico → service crea cabecera + items copiados.
3. **Edición tras crear**: admin puede reordenar, quitar (DELETE solo del item, NO de tabla origen), añadir desde propuesta 12e, reasignar técnico. Solo si `status != completada`.
4. **Items ambiguous** (averías con `piv_id = NULL`): **incluidos** con warning visual. Técnico en PWA (12g) los verá con badge.
5. **Cron daily 12b.4 mantenido sin tocar**. La interacción con `lv_ruta_dia` es vía FK lógica `lv_revision_pendiente_id`. Si el cron promueve un preventivo a `asignacion` legacy, `lv_revision_pendiente.asignacion_id` queda set; el item de la ruta sigue apuntando al `lv_revision_pendiente_id` independiente. Sin duplicación.

**Decisiones menores cerradas yo**:
- Endpoint UI: Resource Filament `LvRutaDiaResource` slug `rutas-dia`, sidebar Planificación.
- Modal creación: selector técnico (Tecnico::activos), fecha (default today, lock al confirmar), checkbox "Incluir averías ambiguas" (default ON).
- Tabla items en edit: orden + panel + tipo badge + municipio + km + status + acciones (↑↓, eliminar).
- Status enum: `planificada` (default) | `en_progreso` | `completada` | `cancelada`.

## Restricciones inviolables

- **NO modificar tablas legacy** (`piv`, `modulo`, `tecnico`, `averia`, `asignacion`, etc.). Solo crea `lv_ruta_dia*`.
- **NO modificar tablas `lv_*` existentes** (`lv_averia_icca`, `lv_revision_pendiente`, `lv_piv_ruta*`).
- **NO tocar cron daily 12b.4** (`lv:promote-revisiones-to-asignacion`). Sigue corriendo igual.
- **NO tocar 12e service ni page**. Solo añadir botón header en la page que invoca el service de snapshot.
- **NO PWA técnico** en este bloque. Solo admin Filament. La PWA llega en 12g.
- **PHP 8.2 floor**, sin paquetes nuevos.
- **NO ejecutar** `php artisan migrate` ni tinker contra prod (`.env` LOCAL apunta SiteGround, lección consolidada).
- **DESIGN.md Carbon** obligatorio. Slug explícito `rutas-dia` (08b pluralizer). `$infolist` (no `$i`) en closures Filament Infolist (08c). NO RelationManager con relación incompatible en ViewRecord (08g/h — usar partial Blade si hace falta drill-in).
- **ActionsPosition::AfterColumns** en tablas con actions (lección 12d UX).
- **Tests Pest verde**. Suite actual 343 → ≥370 verde.
- **CI 3/3 verde**, **Pint clean**.

## Plan de cambios

### Step 1 — Migration `2026_05_07_000000_create_lv_ruta_dia_tables.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_ruta_dia', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('tecnico_id')->comment('FK lógica a tecnico legacy (sin constraint físico, ADR-0002)');
            $t->date('fecha');
            $t->enum('status', ['planificada', 'en_progreso', 'completada', 'cancelada'])->default('planificada');
            $t->text('notas_admin')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();

            $t->unique(['tecnico_id', 'fecha'], 'uniq_tecnico_fecha');
            $t->index('fecha', 'idx_fecha');
            $t->index('status', 'idx_status');

            $t->foreign('created_by_user_id', 'fk_ruta_dia_created_by')
                ->references('id')->on('lv_users')
                ->nullOnDelete();
        });

        Schema::create('lv_ruta_dia_item', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ruta_dia_id')->constrained('lv_ruta_dia')->cascadeOnDelete();
            $t->unsignedSmallInteger('orden');
            $t->enum('tipo_item', ['correctivo', 'preventivo', 'carry_over']);
            $t->unsignedBigInteger('lv_averia_icca_id')->nullable()->comment('FK lógica si tipo=correctivo');
            $t->unsignedBigInteger('lv_revision_pendiente_id')->nullable()->comment('FK lógica si tipo=preventivo|carry_over');
            $t->enum('status', ['pendiente', 'en_progreso', 'cerrado', 'no_resuelto'])->default('pendiente');
            $t->text('causa_no_resolucion')->nullable()->comment('Set por técnico en 12g si no puede resolver');
            $t->text('notas_tecnico')->nullable();
            $t->timestamp('cerrado_at')->nullable();
            $t->timestamps();

            $t->index(['ruta_dia_id', 'orden'], 'idx_ruta_orden');
            $t->index('status', 'idx_item_status');
            $t->index('lv_averia_icca_id', 'idx_averia_icca');
            $t->index('lv_revision_pendiente_id', 'idx_revision_pendiente');

            // CHECK lógico: exactamente uno de los dos FK lógicos NOT NULL.
            // En MySQL 8.0+ los CHECK constraints son enforced. Validamos también
            // a nivel app en el modelo via boot(creating).
            $t->foreign('lv_averia_icca_id', 'fk_item_averia_icca')
                ->references('id')->on('lv_averia_icca')
                ->nullOnDelete();
            // NO añadir FK física a lv_revision_pendiente para evitar problemas si
            // se borra esa fila (caso edge). Mantener FK lógica.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_ruta_dia_item');
        Schema::dropIfExists('lv_ruta_dia');
    }
};
```

### Step 2 — Modelos `LvRutaDia` y `LvRutaDiaItem`

`app/Models/LvRutaDia.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LvRutaDiaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LvRutaDia extends Model
{
    use HasFactory;

    protected $table = 'lv_ruta_dia';

    public const STATUS_PLANIFICADA = 'planificada';
    public const STATUS_EN_PROGRESO = 'en_progreso';
    public const STATUS_COMPLETADA = 'completada';
    public const STATUS_CANCELADA = 'cancelada';

    public const STATUSES = [
        self::STATUS_PLANIFICADA,
        self::STATUS_EN_PROGRESO,
        self::STATUS_COMPLETADA,
        self::STATUS_CANCELADA,
    ];

    public const STATUSES_EDITABLES = [
        self::STATUS_PLANIFICADA,
        self::STATUS_EN_PROGRESO,
    ];

    protected $fillable = [
        'tecnico_id', 'fecha', 'status', 'notas_admin', 'created_by_user_id',
    ];

    protected $casts = [
        'tecnico_id' => 'integer',
        'fecha' => 'date',
        'created_by_user_id' => 'integer',
    ];

    protected static function newFactory(): LvRutaDiaFactory
    {
        return LvRutaDiaFactory::new();
    }

    public function items(): HasMany
    {
        return $this->hasMany(LvRutaDiaItem::class, 'ruta_dia_id')->orderBy('orden');
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(Tecnico::class, 'tecnico_id', 'tecnico_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::STATUSES_EDITABLES, true);
    }

    public function scopeDelDia(Builder $q, \DateTimeInterface $fecha): void
    {
        $q->whereDate('fecha', $fecha);
    }
}
```

`app/Models/LvRutaDiaItem.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LvRutaDiaItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LvRutaDiaItem extends Model
{
    use HasFactory;

    protected $table = 'lv_ruta_dia_item';

    public const TIPO_CORRECTIVO = 'correctivo';
    public const TIPO_PREVENTIVO = 'preventivo';
    public const TIPO_CARRY_OVER = 'carry_over';

    public const STATUS_PENDIENTE = 'pendiente';
    public const STATUS_EN_PROGRESO = 'en_progreso';
    public const STATUS_CERRADO = 'cerrado';
    public const STATUS_NO_RESUELTO = 'no_resuelto';

    protected $fillable = [
        'ruta_dia_id', 'orden', 'tipo_item',
        'lv_averia_icca_id', 'lv_revision_pendiente_id',
        'status', 'causa_no_resolucion', 'notas_tecnico', 'cerrado_at',
    ];

    protected $casts = [
        'ruta_dia_id' => 'integer',
        'orden' => 'integer',
        'lv_averia_icca_id' => 'integer',
        'lv_revision_pendiente_id' => 'integer',
        'cerrado_at' => 'datetime',
    ];

    protected static function newFactory(): LvRutaDiaItemFactory
    {
        return LvRutaDiaItemFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $hasAveria = $item->lv_averia_icca_id !== null;
            $hasRevision = $item->lv_revision_pendiente_id !== null;
            if ($hasAveria === $hasRevision) {
                throw new \DomainException(
                    'lv_ruta_dia_item: exactamente uno de lv_averia_icca_id o lv_revision_pendiente_id debe estar set, no ambos ni ninguno.'
                );
            }
        });
    }

    public function rutaDia(): BelongsTo
    {
        return $this->belongsTo(LvRutaDia::class, 'ruta_dia_id');
    }

    public function averiaIcca(): BelongsTo
    {
        return $this->belongsTo(LvAveriaIcca::class, 'lv_averia_icca_id');
    }

    public function revisionPendiente(): BelongsTo
    {
        return $this->belongsTo(LvRevisionPendiente::class, 'lv_revision_pendiente_id');
    }
}
```

### Step 3 — Service `RutaDiaSnapshotService`

`app/Services/RutaDiaSnapshotService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\Tecnico;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Persiste un snapshot del PlanificadorDelDiaService como ruta del día concreta
 * asignada a un técnico. NO toca tablas legacy ni cron 12b.4.
 */
final class RutaDiaSnapshotService
{
    public function __construct(
        private readonly PlanificadorDelDiaService $planificador,
    ) {}

    /**
     * @throws DomainException si técnico no existe / no activo / ya tiene ruta ese día.
     */
    public function snapshot(
        int $tecnicoId,
        CarbonInterface $fecha,
        User $admin,
        bool $incluirAmbiguas = true,
    ): LvRutaDia {
        $fechaInm = CarbonImmutable::instance($fecha)->setTimezone('Europe/Madrid')->startOfDay();

        // Verificar técnico existe y está activo.
        $tecnico = Tecnico::query()->where('tecnico_id', $tecnicoId)->where('status', 1)->first();
        if ($tecnico === null) {
            throw new DomainException("Técnico {$tecnicoId} no existe o no está activo.");
        }

        // Verificar UNIQUE (tecnico_id, fecha).
        $existing = LvRutaDia::query()
            ->where('tecnico_id', $tecnicoId)
            ->whereDate('fecha', $fechaInm->format('Y-m-d'))
            ->first();
        if ($existing !== null) {
            throw new DomainException(
                "Ya existe ruta para técnico {$tecnicoId} el {$fechaInm->format('Y-m-d')} (id={$existing->id})."
            );
        }

        return DB::transaction(function () use ($tecnicoId, $fechaInm, $admin, $incluirAmbiguas): LvRutaDia {
            $resultado = $this->planificador->computar($fechaInm);

            $ruta = LvRutaDia::create([
                'tecnico_id' => $tecnicoId,
                'fecha' => $fechaInm->format('Y-m-d'),
                'status' => LvRutaDia::STATUS_PLANIFICADA,
                'created_by_user_id' => $admin->id,
            ]);

            $orden = 1;
            foreach ($resultado['grupos'] as $grupo) {
                foreach ($grupo['items'] as $item) {
                    if (! $incluirAmbiguas && $item['piv_id'] === null) {
                        continue;
                    }

                    $tipo = $item['tipo'];
                    $lvAveriaIccaId = $tipo === LvRutaDiaItem::TIPO_CORRECTIVO ? $item['lv_id'] : null;
                    $lvRevisionPendienteId = in_array($tipo, [
                        LvRutaDiaItem::TIPO_PREVENTIVO, LvRutaDiaItem::TIPO_CARRY_OVER,
                    ], true) ? $item['lv_id'] : null;

                    LvRutaDiaItem::create([
                        'ruta_dia_id' => $ruta->id,
                        'orden' => $orden++,
                        'tipo_item' => $tipo,
                        'lv_averia_icca_id' => $lvAveriaIccaId,
                        'lv_revision_pendiente_id' => $lvRevisionPendienteId,
                        'status' => LvRutaDiaItem::STATUS_PENDIENTE,
                    ]);
                }
            }

            return $ruta->load('items');
        });
    }
}
```

### Step 4 — Filament Resource `LvRutaDiaResource`

`app/Filament/Resources/LvRutaDiaResource.php`:

- Slug `rutas-dia`.
- Nav group "Planificación", sort 3.
- Nav icon `heroicon-o-clipboard-document-check`.
- Badge nav: count `STATUS_PLANIFICADA + EN_PROGRESO` del día actual.

**Form** (Edit page):
- `tecnico_id`: Select de técnicos activos (`Tecnico::where('status',1)`), required.
- `fecha`: DatePicker, required, disabled si ya creada.
- `status`: Select STATUSES.
- `notas_admin`: Textarea.

**Table** (List):
- Columnas: técnico (`tecnico.nombre_completo`), fecha (mono), status badge color, items_count, ambiguous_count (count items con piv_id NULL via subquery), created_by (admin name), created_at.
- Filtros: técnico, fecha range, status, mes/año (default mes actual).
- Default sort: fecha DESC, tecnico_id.
- Pagination 25/50/100.
- ActionsPosition AfterColumns.
- ViewAction slideOver con infolist (cabecera + tabla items en orden).
- EditAction.
- BulkAction cancel (status=cancelada).

**RelationManager** `ItemsRelationManager`:
- Tabla: orden, panel (mono), tipo badge color (correctivo rojo / preventivo azul / carry amber), municipio, km, status, ambiguous warning, actions.
- ReorderableColumn por `orden`.
- DeleteAction inline (solo si ruta editable).
- DetachAction NO (no hay pivot — son rows directas).
- CreateAction: añadir item desde propuesta 12e (modal con selector item disponible).

Pages:
- `app/Filament/Resources/LvRutaDiaResource/Pages/ListLvRutaDias.php`
- `app/Filament/Resources/LvRutaDiaResource/Pages/EditLvRutaDia.php`
- `app/Filament/Resources/LvRutaDiaResource/Pages/ViewLvRutaDia.php` (opcional, slideOver desde List ya cubre)
- NO Create page (la creación viene de Planificador del día via service).

### Step 5 — Botón "Crear ruta del día" en Page Planificador del día (12e)

Modificar `app/Filament/Pages/PlanificadorDelDia.php` añadiendo header action.

Añadir método:

```php
protected function getHeaderActions(): array
{
    return [
        \Filament\Actions\Action::make('crearRutaDia')
            ->label('Crear ruta del día')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('primary')
            ->visible(fn (): bool => ($this->resultado['total_items'] ?? 0) > 0)
            ->form([
                \Filament\Forms\Components\Select::make('tecnico_id')
                    ->label('Técnico')
                    ->options(\App\Models\Tecnico::query()
                        ->where('status', 1)
                        ->orderBy('nombre_completo')
                        ->pluck('nombre_completo', 'tecnico_id')
                        ->all())
                    ->required()
                    ->searchable(),
                \Filament\Forms\Components\Checkbox::make('incluir_ambiguas')
                    ->label('Incluir averías ambiguas (sin piv_id resuelto)')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                $state = $this->form->getState();
                $fecha = \Carbon\CarbonImmutable::parse($state['fecha'], 'Europe/Madrid');

                try {
                    $ruta = app(\App\Services\RutaDiaSnapshotService::class)->snapshot(
                        (int) $data['tecnico_id'],
                        $fecha,
                        auth()->user(),
                        (bool) $data['incluir_ambiguas'],
                    );
                } catch (\DomainException $e) {
                    \Filament\Notifications\Notification::make()
                        ->title('No se pudo crear la ruta')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                    return;
                }

                \Filament\Notifications\Notification::make()
                    ->title("Ruta del día creada con {$ruta->items->count()} items")
                    ->success()
                    ->send();

                $this->redirect(\App\Filament\Resources\LvRutaDiaResource::getUrl('edit', ['record' => $ruta]));
            }),
    ];
}
```

**Importante**: la action lee `$this->form->getState()` para obtener la fecha actual del DatePicker (lección 12d Filament getState).

### Step 6 — Tests Pest (~25-30)

#### 6.1 — `tests/Feature/Models/LvRutaDiaSchemaTest.php`

Schema verify + UNIQUE compuesto + ENUM constraints + CHECK manual via DomainException + relaciones + scope delDia.

#### 6.2 — `tests/Feature/Services/RutaDiaSnapshotServiceTest.php`

- Snapshot crea ruta + items en orden.
- Items con `tipo=correctivo` → `lv_averia_icca_id` set.
- Items con `tipo=preventivo`/`carry_over` → `lv_revision_pendiente_id` set.
- `incluirAmbiguas=false` excluye items con `piv_id NULL`.
- DomainException si técnico no existe.
- DomainException si técnico no activo (`status=0`).
- DomainException si UNIQUE (tecnico_id, fecha) ya existe.
- Transacción rollback si fallo intermedio.
- Orden secuencial respeta agrupación por ruta + km ASC del 12e.

#### 6.3 — `tests/Feature/Filament/LvRutaDiaResourceTest.php`

- Admin puede acceder /admin/rutas-dia → 200.
- Non-admin → 403.
- Slug explícito `rutas-dia`.
- List default sort fecha DESC.
- Filtros técnico, fecha, status funcionan.
- Edit page muestra items.
- Ruta `status=completada` → form fields disabled.
- BulkAction cancel cambia status a `cancelada`.

#### 6.4 — `tests/Feature/Filament/PlanificadorDelDiaCrearRutaTest.php`

- Header action "Crear ruta del día" visible si `total_items > 0`.
- Modal con selector técnico + checkbox ambiguas.
- Submit OK crea ruta + redirect a edit.
- Submit con técnico inactivo → notification danger.
- Submit con UNIQUE conflict → notification danger.

### Step 7 — Smoke local (text-only)

```bash
php artisan test tests/Feature/Models/LvRutaDiaSchemaTest.php
php artisan test tests/Feature/Services/RutaDiaSnapshotServiceTest.php
php artisan test tests/Feature/Filament/LvRutaDiaResourceTest.php
php artisan test tests/Feature/Filament/PlanificadorDelDiaCrearRutaTest.php
php -d memory_limit=512M vendor/bin/pest
./vendor/bin/pint --test
```

**NO ejecutar contra prod**.

## DoD

- [ ] Migration `lv_ruta_dia` + `lv_ruta_dia_item` con UNIQUE compuesto + ENUMs + 4 indexes + 2 FK físicas (created_by + averia_icca).
- [ ] Modelo `LvRutaDia` con 4 STATUS_* + 1 STATUSES_EDITABLES + scope `delDia` + relaciones + `isEditable()`.
- [ ] Modelo `LvRutaDiaItem` con 3 TIPO_* + 4 STATUS_* + boot creating CHECK xor + relaciones BelongsTo.
- [ ] Factories LvRutaDia + LvRutaDiaItem.
- [ ] Service `RutaDiaSnapshotService` transaccional con validaciones (técnico activo, UNIQUE).
- [ ] Resource Filament `LvRutaDiaResource` slug `rutas-dia`, nav Planificación, badge count rutas hoy, tabla con columnas + filtros + ViewAction slideOver, RelationManager Items reordenable.
- [ ] Botón "Crear ruta del día" header action en `PlanificadorDelDia` page con modal selector técnico + checkbox ambiguas + redirect a edit.
- [ ] ~25-30 tests Pest. Suite total 343 → ≥370 verde.
- [ ] CI 3/3 verde.
- [ ] Pint clean.
- [ ] Smoke local OK.

## Smoke real obligatorio post-merge (sesión dedicada ~30-45 min)

1. Backup fresh prod cifrado (runbook nuevo `docs/runbooks/backups/2026-05-XX-pre-bloque-12f.md`).
2. `migrate --pretend` → CREATE TABLE lv_ruta_dia + lv_ruta_dia_item. Cero ALTER legacy.
3. `migrate --force`.
4. Login admin → Planificación → Planificador del día → click "Crear ruta del día".
5. Modal: selector técnico (esperado: lista de técnicos activos en BD prod, ~10-15) + checkbox ambiguas. Selecciona técnico real + confirma.
6. Notification "Ruta del día creada con N items" + redirect a `/admin/rutas-dia/{id}/edit`.
7. Verificar BD:
   - `lv_ruta_dia` 1 fila con tecnico_id correcto, fecha=today, status=planificada.
   - `lv_ruta_dia_item` ~28 filas (averías ICCA + 0 preventivos + 0 carry overs hoy).
   - Items con `tipo_item=correctivo` y `lv_averia_icca_id` apuntando a las 28 averías ICCA.
   - Orden secuencial 1..28 agrupado por ruta + km ASC.
8. Tabla items en edit page: drag-drop reorder funciona (cambia orden en BD).
9. Eliminar 1 item via DeleteAction → BD `lv_ruta_dia_item` count -1.
10. `/admin/rutas-dia` listado: 1 ruta visible con badge count + filtros funcionales.
11. **Cleanup**: borrar la ruta smoke (CASCADE elimina items) + verificar `lv_averia_icca` y `lv_revision_pendiente` intactos (FK lógica nullOnDelete no aplica porque borramos lv_ruta_dia, no las averías).
12. Estado final: BD prod con `lv_ruta_dia` y `lv_ruta_dia_item` tablas vacías. Migration aplicada.

## Riesgos y decisiones diferidas

1. **Reasignar técnico**: si admin cambia `tecnico_id` en una ruta `planificada`, no hay efectos secundarios (la ruta cambia de dueño). En PWA técnico (12g) la ruta aparecerá al nuevo técnico, no al viejo. **Decisión**: permitir libremente. Audit trail en `updated_at` de `lv_ruta_dia`.
2. **Conflicto UNIQUE en reasignación**: si admin intenta cambiar `tecnico_id` a uno que ya tiene ruta ese día, MySQL rechaza por UNIQUE. Filament Resource debe validar antes con custom rule. **Riesgo conocido — añadir validation rule en form**.
3. **Items huérfanos**: si admin borra una avería ICCA en `lv_averia_icca` (no implementado, es read-only), el item de la ruta queda con FK lógica colgando. La FK física `fk_item_averia_icca ON DELETE SET NULL` lo gestiona. Si pasa, el item quedará con `lv_averia_icca_id = NULL` y `lv_revision_pendiente_id = NULL` simultáneamente — **viola el CHECK manual del modelo**. Soft constraint, no hard. Aceptable por ahora.
4. **Cron daily 12b.4 + 12f**: si admin crea ruta del día con preventivos AND el cron diario corre después, el cron promueve los preventivos a `asignacion` legacy (por su filtro `asignacion_id IS NULL`). Resultado: el item de la ruta sigue apuntando al `lv_revision_pendiente_id`, y `lv_revision_pendiente.asignacion_id` queda set. Sin conflicto. La PWA técnico (12g) usará `lv_ruta_dia_item` como fuente de verdad. Cuando el técnico cierre el item, el hook de `AsignacionCierreService` marca `lv_revision_pendiente.status=completada`.
5. **Status `en_progreso`**: ¿quién lo cambia? **Decisión**: la PWA técnico (12g) lo cambia automáticamente al primer "iniciar trabajo". 12f deja la columna preparada pero NO cambia el status automáticamente.
6. **Status `completada`**: ¿quién lo cambia? **Decisión**: cuando todos los items tienen `status IN (cerrado, no_resuelto)`. Lógica en 12g/12h.
7. **Optimizador ruta avanzado**: el orden actual viene del 12e (ruta + km ASC). Si admin reordena manualmente, su orden manual queda. Si añade un item nuevo, va al final. Algoritmo más sofisticado (TSP, lat/lng) es Bloque futuro.

## REPORTE FINAL (formato esperado)

```
## Bloque 12f — REPORTE FINAL

### Estado
- Branch: bloque-12f-ruta-dia-y-asignacion-tecnico
- Commits: N
- Tests: 343 → ~370 verde
- CI: 3/3 verde
- Pint: clean
- Smoke local: tests + suite

### Decisiones aplicadas
- 5 puntos cerrados con usuario.
- Cron 12b.4 mantenido sin tocar.
- Items ambiguous incluidos por defecto.

### Pivots respecto al prompt
- (si los hubo)
```

---

## Aplicación checklist obligatoria

| Sección | Aplicado | Cómo |
|---|---|---|
| 1. Compatibilidad framework | ✓ | Filament Resource + RelationManager con relación HasMany simple (NO HasManyThrough — lección 08e). Slug explícito (08b). `$infolist` no `$i` (08c). ActionsPosition AfterColumns (12d). FK física solo a `lv_*`, FK lógica a legacy (ADR-0002). |
| 2. Inferir de app vieja | N/A | App vieja PHP 2014 NO tiene "ruta del día" como entidad. Feature 100% nueva. |
| 3. Smoke real obligatorio | ✓ | Backup fresh + migrate + crear ruta del día con técnico real + verificar BD + cleanup. Estado prod final con tabla vacía tras cleanup. |
| 4. Test pivots = banderazo rojo | ✓ | Tests Filament con `Livewire::test()` reales + tests del service con DB::transaction. Si Copilot pivota a tests-del-modelo, banderazo. |
| 5. Datos prod-shaped | ✓ | Tests cubren: técnico activo/inactivo/inexistente, UNIQUE conflict, items ambiguous con piv_id NULL, tipos correctivo/preventivo/carry_over, CHECK xor enforcement. |
