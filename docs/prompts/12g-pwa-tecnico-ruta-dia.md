# Bloque 12g — PWA técnico ruta del día mixta + integración automática con legacy

## Contexto

Tras Bloques 12c (rutas oficiales) + 12d (CSV SGIP/ICCA) + 12e (Planificador) + 12f (persistir ruta del día), el técnico necesita una **PWA mobile-first** para ejecutar la ruta del día asignada.

Pattern existente del Bloque 11d (PWA técnico Glovo-pattern):
- `/tecnico/login` (Volt) auth con SHA1→bcrypt lazy.
- `/tecnico` dashboard cards de asignaciones abiertas (Bloque 11d).
- `/tecnico/asignaciones/{asignacion}` cierre flow multi-step (Bloque 11b/d).

12g **reemplaza el dashboard `/tecnico`** con la ruta del día asignada (12f). Si el técnico no tiene ruta asignada para hoy, fallback al pattern 11d (asignaciones legacy abiertas via cron 12b.4).

Cierre del item activa **integración automática con `AsignacionCierreService` (Bloque 11b)**: para preventivos/carry overs crea avería stub + asignación legacy + revision legacy. Para correctivos solo cierra el item (avería ICCA se cierra externamente en SGIP por admin en 12h).

## Decisiones cerradas con el usuario antes del prompt

**6 puntos aprobados**:

1. **Endpoint UI**: reemplazar dashboard `/tecnico` → muestra ruta del día si existe, fallback a pattern 11d (asignaciones legacy abiertas) si no hay ruta.
2. **Visualización**: cards apiladas mobile-first con stripe lateral por tipo (rojo correctivo / azul preventivo / amber carry over). Cada card con foto panel + parada_cod + municipio nombre + km + categoría + chevron derecho. Tap → página de cierre.
3. **Cierre — Opción B integración automática**:
   - Correctivo: solo `lv_ruta_dia_item.status='cerrado'`. Avería ICCA se cierra fuera (en SGIP) por admin (12h).
   - Preventivo/carry: hook automático crea avería stub + asignación + revision legacy via `AsignacionCierreService::cerrar()`. Si ya tiene `asignacion_id` (cron 12b.4), reusa esa asignación.
4. **Form cierre por tipo**:
   - Correctivo: estado_final (Resuelto/No resuelto) + notas_tecnico (con Web Speech API es-ES) + foto opcional.
   - Preventivo/carry: checklist OK/KO/N-A (aspecto, audio, líneas, fecha, ruta, precision_paso) + notas_tecnico + foto opcional.
   - Si `status='no_resuelto'`: causa_no_resolucion **REQUIRED** + Select categoría (Sin tensión / Software Indra / Pieza no disponible / Acceso bloqueado / Otro).
5. **Status automático ruta**:
   - `planificada` → `en_progreso` al primer item cerrado.
   - `en_progreso` → `completada` cuando todos los items tienen `status IN (cerrado, no_resuelto)`.
6. **Carry over**: mostrar `decision_notas` del registro origen del mes anterior como contexto para el técnico.

## Restricciones inviolables

- **PWA mobile-first** Volt. Cards 88px+ tap target (Bloque 11a). Fonts Plex Carbon (Bloque 09d).
- **NO modificar tablas legacy** (`piv`, `modulo`, `tecnico`, `averia`, `asignacion`, `correctivo`, `revision`). Solo INSERT a través de `AsignacionCierreService` existente que ya respeta legacy schema.
- **NO modificar `AsignacionCierreService` ni `LvRevisionPendiente` ni `LvAveriaIcca`**. Service nuevo `RutaDiaItemCierreService` los compone.
- **NO tocar PWA login ni routing** (existente Bloque 11a/ab).
- **NO ejecutar** migrate ni tinker contra prod (`.env` LOCAL apunta SiteGround, lección consolidada).
- **PHP 8.2 floor**, sin paquetes nuevos.
- **Tests Pest verde**. Suite actual 370 → ≥395 verde.
- **CI 3/3 verde**, **Pint clean**.
- **Slug explícito** routes con name `tecnico.ruta-item.cierre`.
- **Lección 11d**: backward-compat de campos en estado del componente Volt (NO romper tests del 11b).
- **Lección 12b.3 / 12d**: el `RutaDiaItemCierreService` debe ser idempotente (si item ya cerrado, lanzar excepción clara, no doble cierre).

## Plan de cambios

### Step 1 — Migration `2026_05_07_000001_create_lv_ruta_dia_item_imagen_table.php`

Las fotos del cierre necesitan tabla propia: `lv_correctivo_imagen` solo aplica a correctivo legacy (tipo=1), pero items correctivos (ICCA) NO crean correctivo legacy. Y items preventivos crean `revision` legacy que NO tiene tabla de imágenes legacy. Solución: tabla nueva.

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
        Schema::create('lv_ruta_dia_item_imagen', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ruta_dia_item_id')->constrained('lv_ruta_dia_item')->cascadeOnDelete();
            $t->string('url', 500)->comment('Path en disk public, ej piv-images/ruta-dia-item/abc.jpg');
            $t->unsignedSmallInteger('posicion')->default(1);
            $t->timestamps();

            $t->index(['ruta_dia_item_id', 'posicion'], 'idx_item_posicion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_ruta_dia_item_imagen');
    }
};
```

### Step 2 — Modelo `LvRutaDiaItemImagen`

`app/Models/LvRutaDiaItemImagen.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LvRutaDiaItemImagenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LvRutaDiaItemImagen extends Model
{
    use HasFactory;

    protected $table = 'lv_ruta_dia_item_imagen';

    protected $fillable = ['ruta_dia_item_id', 'url', 'posicion'];

    protected $casts = [
        'ruta_dia_item_id' => 'integer',
        'posicion' => 'integer',
    ];

    protected static function newFactory(): LvRutaDiaItemImagenFactory
    {
        return LvRutaDiaItemImagenFactory::new();
    }

    public function rutaDiaItem(): BelongsTo
    {
        return $this->belongsTo(LvRutaDiaItem::class, 'ruta_dia_item_id');
    }
}
```

Reverse en `LvRutaDiaItem.php`:

```php
public function imagenes(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(LvRutaDiaItemImagen::class, 'ruta_dia_item_id')->orderBy('posicion');
}
```

### Step 3 — Service `RutaDiaItemCierreService`

`app/Services/RutaDiaItemCierreService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\LvRutaDiaItemImagen;
use App\Models\Tecnico;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cierra un item de ruta del día. Integra con AsignacionCierreService
 * para preventivos/carry overs (Opción B aprobada).
 *
 * - Correctivo (avería ICCA): solo marca item cerrado/no_resuelto.
 *   Avería ICCA se cierra externamente en SGIP por admin (Bloque 12h).
 * - Preventivo/carry over: si ya tiene asignacion_id, reusa. Si no,
 *   crea avería stub + asignación + revision legacy via
 *   AsignacionCierreService::cerrar().
 *
 * Auto-actualiza status de la ruta:
 * - planificada → en_progreso al primer item cerrado.
 * - → completada cuando todos los items están cerrado | no_resuelto.
 */
final class RutaDiaItemCierreService
{
    public const NOTAS_AVERIA_STUB = 'Revisión preventiva mensual (cierre técnico desde ruta)';

    public function __construct(
        private readonly AsignacionCierreService $asignacionCierre,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{item: LvRutaDiaItem, ruta: LvRutaDia}
     *
     * @throws DomainException si tecnico no es dueño de la ruta.
     * @throws ValidationException si item ya cerrado.
     */
    public function cerrar(LvRutaDiaItem $item, array $data, Tecnico $tecnico): array
    {
        return DB::transaction(function () use ($item, $data, $tecnico): array {
            $item->refresh();
            $ruta = $item->rutaDia;

            // Security: técnico solo puede cerrar items de SU ruta.
            if ((int) $ruta->tecnico_id !== (int) $tecnico->tecnico_id) {
                throw new DomainException('No autorizado: este item no pertenece a tu ruta del día.');
            }

            // Idempotencia: no doble cierre.
            if (in_array($item->status, [LvRutaDiaItem::STATUS_CERRADO, LvRutaDiaItem::STATUS_NO_RESUELTO], true)) {
                throw ValidationException::withMessages(['cerrar' => 'Este item ya fue cerrado.']);
            }

            // Validar status entrante.
            $newStatus = (string) ($data['status'] ?? LvRutaDiaItem::STATUS_CERRADO);
            if (! in_array($newStatus, [LvRutaDiaItem::STATUS_CERRADO, LvRutaDiaItem::STATUS_NO_RESUELTO], true)) {
                throw ValidationException::withMessages(['status' => 'Status inválido.']);
            }

            // Si no_resuelto, exigir causa.
            $causa = trim((string) ($data['causa_no_resolucion'] ?? ''));
            if ($newStatus === LvRutaDiaItem::STATUS_NO_RESUELTO && $causa === '') {
                throw ValidationException::withMessages(['causa_no_resolucion' => 'Indica la causa de no resolución.']);
            }

            // Update item.
            $item->update([
                'status' => $newStatus,
                'notas_tecnico' => trim((string) ($data['notas_tecnico'] ?? '')) !== '' ? trim((string) $data['notas_tecnico']) : null,
                'causa_no_resolucion' => $newStatus === LvRutaDiaItem::STATUS_NO_RESUELTO ? $causa : null,
                'cerrado_at' => CarbonImmutable::now('Europe/Madrid'),
            ]);

            // Persistir fotos opcionales en lv_ruta_dia_item_imagen.
            foreach (($data['fotos'] ?? []) as $idx => $url) {
                LvRutaDiaItemImagen::create([
                    'ruta_dia_item_id' => $item->id,
                    'url' => (string) $url,
                    'posicion' => $idx + 1,
                ]);
            }

            // Integración con tablas legacy SOLO para preventivo/carry over y SOLO si fue cerrado (no si no_resuelto).
            if ($newStatus === LvRutaDiaItem::STATUS_CERRADO &&
                in_array($item->tipo_item, [LvRutaDiaItem::TIPO_PREVENTIVO, LvRutaDiaItem::TIPO_CARRY_OVER], true)) {
                $this->cerrarRevisionLegacy($item, $data, $tecnico);
            }

            // Auto-actualizar status ruta.
            $this->actualizarStatusRuta($ruta);

            return ['item' => $item->fresh(['imagenes', 'rutaDia']), 'ruta' => $ruta->fresh()];
        });
    }

    /**
     * Crea o reusa asignación legacy + cierra via AsignacionCierreService.
     *
     * @param  array<string, mixed>  $data
     */
    private function cerrarRevisionLegacy(LvRutaDiaItem $item, array $data, Tecnico $tecnico): void
    {
        $revisionPendiente = $item->revisionPendiente;
        if ($revisionPendiente === null) {
            return;
        }

        $asignacion = null;

        // Si ya tiene asignacion_id (cron 12b.4 promovió), reusarla.
        if ($revisionPendiente->asignacion_id !== null) {
            $asignacion = Asignacion::find($revisionPendiente->asignacion_id);
        }

        // Si no, crear stub avería + asignación (mismo flujo que cron promotor 12b.4).
        if ($asignacion === null) {
            $averia = Averia::create([
                'piv_id' => $revisionPendiente->piv_id,
                'notas' => self::NOTAS_AVERIA_STUB,
                'status' => 1,
            ]);
            $asignacion = Asignacion::create([
                'averia_id' => $averia->averia_id,
                'tecnico_id' => $tecnico->tecnico_id,
                'tipo' => Asignacion::TIPO_REVISION,
                'fecha' => CarbonImmutable::now('Europe/Madrid')->format('Y-m-d'),
                'status' => 1,
            ]);
            $revisionPendiente->update(['asignacion_id' => $asignacion->asignacion_id]);
        }

        // Si tecnico_id de la asignación es NULL (cron lo dejó NULL), asignar al técnico actual.
        if ($asignacion->tecnico_id === null) {
            $asignacion->update(['tecnico_id' => $tecnico->tecnico_id]);
        }

        // AsignacionCierreService::cerrar valida idempotencia (lanza si ya tiene revision).
        // Si ya cerrada antes (caso edge: cron daily creó asignación + admin la cerró aparte),
        // capturar la excepción y continuar sin crear duplicado.
        try {
            $this->asignacionCierre->cerrar($asignacion->fresh(), [
                'fecha' => CarbonImmutable::now('Europe/Madrid')->format('Y-m-d'),
                'ruta' => trim((string) ($data['ruta'] ?? '')) ?: null,
                'aspecto' => $data['aspecto'] ?? null,
                'funcionamiento' => $data['funcionamiento'] ?? null,
                'actuacion' => $data['actuacion'] ?? null,
                'audio' => $data['audio'] ?? null,
                'lineas' => $data['lineas'] ?? null,
                'fecha_hora' => $data['fecha_hora'] ?? null,
                'precision_paso' => $data['precision_paso'] ?? null,
                'notas' => trim((string) ($data['notas_tecnico'] ?? '')) ?: null,
            ]);
        } catch (ValidationException $e) {
            if (! str_contains((string) collect($e->errors())->flatten()->implode(' '), 'ya tiene')) {
                throw $e;
            }
        }
    }

    private function actualizarStatusRuta(LvRutaDia $ruta): void
    {
        $items = $ruta->items;

        if ($items->isEmpty()) return;

        $allClosed = $items->every(fn ($i) => in_array($i->status, [
            LvRutaDiaItem::STATUS_CERRADO,
            LvRutaDiaItem::STATUS_NO_RESUELTO,
        ], true));

        if ($allClosed && $ruta->status !== LvRutaDia::STATUS_COMPLETADA) {
            $ruta->update(['status' => LvRutaDia::STATUS_COMPLETADA]);
            return;
        }

        $anyClosed = $items->contains(fn ($i) => in_array($i->status, [
            LvRutaDiaItem::STATUS_CERRADO,
            LvRutaDiaItem::STATUS_NO_RESUELTO,
        ], true));

        if ($anyClosed && $ruta->status === LvRutaDia::STATUS_PLANIFICADA) {
            $ruta->update(['status' => LvRutaDia::STATUS_EN_PROGRESO]);
        }
    }
}
```

### Step 4 — Routes

Modificar `routes/web.php` para añadir nueva ruta:

```php
Route::middleware('tecnico')->prefix('tecnico')->group(function () {
    Volt::route('/', 'tecnico.dashboard')->name('tecnico.dashboard');
    Volt::route('/asignaciones/{asignacion}', 'tecnico.cierre')->name('tecnico.asignacion.cierre');
    // NUEVO 12g:
    Volt::route('/ruta-item/{itemId}/cierre', 'tecnico.ruta-item-cierre')->name('tecnico.ruta-item.cierre');
});
```

### Step 5 — Volt component dashboard `/tecnico` reformado

`resources/views/livewire/tecnico/dashboard.blade.php` (refactor del Bloque 11a/d):

Lógica:
1. Cargar `Tecnico` autenticado.
2. Buscar `LvRutaDia` con `tecnico_id` = técnico autenticado y `fecha = today` y `status IN (planificada, en_progreso)`.
3. Si existe: cargar items con eager loading (`piv`, `averiaIcca`, `revisionPendiente.piv`, `revisionPendiente.carryOverOrigen`). Mostrar header "Tu ruta del día (N items)" + cards apiladas.
4. Si NO existe: fallback al pattern 11d — query asignaciones legacy abiertas del técnico.

Cards visualmente:
- Stripe lateral 4px color por tipo (correctivo `bg-red-600`, preventivo `bg-blue-600`, carry over `bg-amber-500`).
- Foto miniatura panel 96x96 (`Piv::current_photo_url` accessor del Bloque 11d).
- Header card: tipo badge + parada_cod (Plex Mono) + ambigua warning si `piv_id` NULL.
- Body: municipio nombre + km + categoría (correctivo) o "Preventivo mensual" (preventivo) o "Carry over desde MM/YYYY" (carry).
- Items cerrados: opacity-60 + checkmark verde.
- Items abiertos: tap card → navigate a `/tecnico/ruta-item/{id}/cierre`.

### Step 6 — Volt component cierre item `/tecnico/ruta-item/{id}/cierre`

`resources/views/livewire/tecnico/ruta-item-cierre.blade.php`:

Lógica:
1. mount(): cargar item por ID + validar `item.rutaDia.tecnico_id == authTecnico.tecnico_id`. Si no, redirect 403.
2. Validar `item.status` no es cerrado/no_resuelto. Si lo es, redirect a dashboard con flash "Ya cerrado".
3. Form multi-step según `item.tipo_item`:
   - **Correctivo**: 3 pasos (qué pasó / acción / confirmar+foto).
     - Paso 1: estado (Resuelto / No resuelto). Si No resuelto, paso 2 cambia a "Causa".
     - Paso 2: notas_tecnico textarea con dictado voz Web Speech (reusar `voice-dictation.blade.php` partial).
     - Paso 3: foto opcional + confirmar.
   - **Preventivo / Carry over**: 2 pasos (checklist / confirmar+foto).
     - Paso 1: checklist 6 ítems (aspecto / audio / líneas / fecha / ruta / precision_paso) con OK/KO/N-A toggleable.
     - Paso 2: notas_tecnico opcional + foto opcional + confirmar.
4. Submit: invocar `RutaDiaItemCierreService::cerrar($item, $data, $tecnico)`.
5. Redirect a `/tecnico` con flash "Item cerrado: panel XXX".

Reusar componentes Bloque 11d:
- `voice-dictation.blade.php` partial.
- Storage path `piv-images/ruta-dia-item/{itemId}/...`.
- Validación foto multi-upload `nullable|array|max:10`.

### Step 7 — Tests Pest (~25-30)

#### 7.1 — `tests/Unit/Services/RutaDiaItemCierreServiceTest.php`

- `cierra item correctivo: solo update item, NO crea revision legacy`.
- `cierra item preventivo con asignacion_id NULL: crea averia stub + asignacion + revision legacy via AsignacionCierreService`.
- `cierra item preventivo con asignacion_id ya set (cron 12b.4): reusa asignacion + crea revision legacy`.
- `cierra item carry over: igual que preventivo`.
- `tecnico no dueño de la ruta lanza DomainException`.
- `item ya cerrado lanza ValidationException idempotente`.
- `status no_resuelto sin causa lanza ValidationException`.
- `status no_resuelto NO crea revision legacy (solo cerrado lo hace)`.
- `auto: planificada → en_progreso al primer cierre`.
- `auto: en_progreso → completada cuando todos cerrados/no_resueltos`.
- `mismo panel con avería + preventivo se cierran independientemente`.
- `fotos persistidas en lv_ruta_dia_item_imagen con posicion correcta`.
- `transacción rollback si AsignacionCierreService falla`.
- `tecnico_id NULL en asignacion legacy se asigna al cerrar`.

#### 7.2 — `tests/Feature/Tecnico/DashboardConRutaTest.php`

- `tecnico autenticado con ruta del día ve cards de items`.
- `tecnico autenticado sin ruta del día cae a fallback dashboard 11d`.
- `cards stripe lateral color por tipo`.
- `items cerrados muestran opacity + checkmark`.
- `tap card abierta redirige a cierre`.
- `tecnico inactivo no puede acceder (middleware EnsureTecnico)`.

#### 7.3 — `tests/Feature/Tecnico/RutaItemCierreTest.php`

- `tecnico cierra correctivo OK: item.status=cerrado + notas + cerrado_at`.
- `tecnico cierra correctivo no_resuelto requiere causa`.
- `tecnico cierra preventivo OK: crea revision legacy`.
- `tecnico cierra carry over OK: crea revision legacy con asignacion_id origen`.
- `redirect a /tecnico con flash success`.
- `acceso a item de otra ruta del técnico → 403`.
- `submit con foto: persiste en lv_ruta_dia_item_imagen`.
- `item ya cerrado redirect dashboard con flash error`.

### Step 8 — Smoke local + smoke real

```bash
php artisan test tests/Unit/Services/RutaDiaItemCierreServiceTest.php
php artisan test tests/Feature/Tecnico/DashboardConRutaTest.php
php artisan test tests/Feature/Tecnico/RutaItemCierreTest.php
php -d memory_limit=512M vendor/bin/pest
./vendor/bin/pint --test
```

**NO ejecutar contra prod**.

## DoD

- [ ] Migration `lv_ruta_dia_item_imagen` (nueva tabla con FK CASCADE a item).
- [ ] Modelo `LvRutaDiaItemImagen` + factory.
- [ ] `LvRutaDiaItem::imagenes()` HasMany.
- [ ] Service `RutaDiaItemCierreService` con `cerrar()` transactional + integración con `AsignacionCierreService` + auto-update status ruta.
- [ ] Volt component `tecnico.dashboard` reformado: ruta del día si existe, fallback 11d si no.
- [ ] Volt component `tecnico.ruta-item-cierre` con flow multi-step según tipo_item.
- [ ] Route `/tecnico/ruta-item/{id}/cierre` named `tecnico.ruta-item.cierre`.
- [ ] Reuso `voice-dictation.blade.php` partial Bloque 11d.
- [ ] Storage path `piv-images/ruta-dia-item/{itemId}/`.
- [ ] ~25-30 tests Pest. Suite total 370 → ≥395 verde.
- [ ] CI 3/3 verde, Pint clean.
- [ ] Smoke local OK.

## Smoke real obligatorio post-merge (sesión dedicada ~45-60 min)

Necesita 1 técnico real activado + ruta del día creada + iPhone real con HTTPS (ngrok pattern Bloque 11c).

1. Backup fresh prod cifrado (runbook nuevo `docs/runbooks/backups/2026-05-XX-pre-bloque-12g.md`).
2. `migrate --pretend` → CREATE TABLE `lv_ruta_dia_item_imagen`. Cero ALTER legacy.
3. `migrate --force`.
4. Activar técnico smoke (id=66 o uno real para test).
5. Admin crea ruta del día con técnico smoke + 28 items reales (CSV SGIP existente).
6. ngrok HTTPS + iPhone real en LAN: técnico login PWA.
7. Dashboard `/tecnico` muestra ruta con cards stripe color.
8. Tap card 1 (correctivo) → flow cierre → estado_final OK + notas + foto opcional.
9. Verificar BD: `lv_ruta_dia_item.status='cerrado'` + 1 fila `lv_ruta_dia_item_imagen` + `lv_averia_icca` intacto (correctivo NO crea revision legacy).
10. Tap card 2 (preventivo si hay) → checklist → cierre.
11. Verificar BD: `lv_ruta_dia_item.status='cerrado'` + `revision` legacy creada + `asignacion.status=2` + `lv_revision_pendiente.status='completada'` (hook Bloque 12b.3).
12. Cerrar más items hasta completar ruta. Auto: `lv_ruta_dia.status='en_progreso'` después del primer + `'completada'` al final.
13. Cleanup: revertir items a status=pendiente + DELETE `lv_ruta_dia_item_imagen` + DELETE revision/asignacion/averia stub creadas + DELETE ruta.
14. Estado final: BD prod limpia.

## Riesgos y decisiones diferidas

1. **Race condition** múltiples técnicos cerrando items mismo tiempo: `DB::transaction` por cierre + UNIQUE compuesto `(tecnico_id, fecha)` en ruta_dia previene duplicación, pero auto-update de `lv_ruta_dia.status` puede tener race. Aceptable — el resultado final converge.
2. **Web Speech API requiere HTTPS**: ngrok suficiente para smoke prod. En el cutover (Bloque 15 deploy SiteGround con HTTPS), funcionará nativo.
3. **Service Worker scope `/tecnico/*`**: heredado de 11c, sigue funcionando. Read-only offline cache (Bloque 11c phased).
4. **Item correctivo cerrado sin avería ICCA actualizada**: la avería ICCA en SGIP externo sigue activa hasta que admin lo cierre en SGIP (12h). Aceptable — la fuente de verdad de SGIP es externa.
5. **AsignacionCierreService valida idempotencia con error**: si admin ya cerró asignación legacy aparte, el service lanza ValidationException. El catch del `cerrarRevisionLegacy()` lo absorbe pero solo si el mensaje contiene "ya tiene". Test específico cubre.
6. **Reasignar técnico mid-ruta**: si admin cambia `lv_ruta_dia.tecnico_id` mientras técnico está cerrando items, próximo cierre desde el técnico viejo lanza `DomainException` (security). Técnico nuevo asume desde donde dejó el viejo. Aceptable.
7. **Causa categorización**: `causa_no_resolucion` es texto libre con Select de sugerencias. Para 12h derivaciones, admin lo lee manualmente. Si en el futuro se quiere automático (Clear Channel auto), refactorizar a enum + handler.

## REPORTE FINAL (formato esperado)

```
## Bloque 12g — REPORTE FINAL

### Estado
- Branch: bloque-12g-pwa-tecnico-ruta-dia
- Commits: N
- Tests: 370 → ~398 verde
- CI: 3/3 verde
- Pint: clean

### Decisiones aplicadas
- 6 puntos cerrados.
- Opción B integración automática con AsignacionCierreService.
- Auto-update status ruta.
- Cron 12b.4 sin tocar.

### Pivots respecto al prompt
- (si los hubo)
```

---

## Aplicación checklist obligatoria

| Sección | Aplicado | Cómo |
|---|---|---|
| 1. Compatibilidad framework | ✓ | Volt + Livewire 3 patterns Bloque 11a/d. Reuso voice-dictation partial. NO RelationManager (no aplica en PWA). Service con DB::transaction (Bloque 11b pattern). |
| 2. Inferir de app vieja | ✓ | App vieja no tiene "ruta del día" como entidad. Pero el cierre de revision/asignación reusa flujo legacy via AsignacionCierreService Bloque 11b (preserva ADR-0001/0004/0006). |
| 3. Smoke real obligatorio | ✓ | Smoke post-merge con técnico real + ngrok + iPhone físico. Verificar revision legacy creada cuando preventivo se cierra. Verificar status auto-actualizado. Cleanup explícito. |
| 4. Test pivots = banderazo rojo | ✓ | Tests usan AsignacionCierreService real + LvRutaDiaItem factory. Si Copilot mockea AsignacionCierreService, banderazo (perdería integración real). |
| 5. Datos prod-shaped | ✓ | Tests cubren: correctivo sin revision legacy, preventivo con/sin asignacion_id pre-set, carry over con periodo origen, mismo panel con avería+preventivo dual, item ya cerrado idempotencia, técnico no dueño, status no_resuelto sin causa, race auto-update status. |
