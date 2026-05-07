# Bloque 12h — Cierre admin + derivaciones (Pendientes de terceros)

> **Eres VS Code Copilot en modo Agent**. Lee este prompt entero antes de empezar.
> Sigue las 11 restricciones inviolables de `.github/copilot-instructions.md`.
> No tocar BD prod (ni `migrate` ni tinker). Local-only durante desarrollo.

## Contexto

Tras 12g (PWA técnico cierra items vía Web Speech API + foto + Option B
`AsignacionCierreService`), queda el **bucle de retorno**: cuando el técnico
marca un item como `no_resuelto` con causa libre, o cuando admin sabe de
antemano que un panel depende de un tercero, el item debe entrar en una
**cola controlada por administración** para gestionar la derivación.

Este bloque añade la pieza final del flujo operativo: el admin tipifica la
causa estructurada, asigna actor responsable, hace seguimiento y cierra la
derivación cuando el tercero resuelve (o devuelve el item a ruta).

**Frase rectora**:

> La derivación no es una tarea nueva para el técnico, sino un estado
> operativo controlado por administración para bloquear temporalmente un
> item cuando depende de un tercero, material, autorización o una causa
> estructurada que impide su resolución directa en ruta.

## Decisiones de scope ya cerradas (no replantear)

1. Catálogo de causas (ver §1 abajo).
2. Schema: tabla nueva `lv_derivacion` con FK a `lv_ruta_dia_item`.
3. Workflow: solo registro admin (sin emails automáticos en MVP).
4. Carry over: item derivado **sale** de lista técnico (status pasa a `derivado`).
5. Vista admin: nuevo Resource `LvDerivacionResource` propio en sidebar.
6. Triggers: ambos válidos — desde item PWA `no_resuelto` y preventivamente desde admin.

## §1. Catálogo `tipo_causa` (constantes + DB enum)

Definir en `LvDerivacion` model como constants:

```php
public const CAUSA_SIN_TENSION         = 'sin_tension';                 // Sin tensión / alimentación externa
public const CAUSA_PANEL_OFFLINE       = 'panel_offline';               // Panel offline / sin comunicaciones
public const CAUSA_INCIDENCIA_SOFTWARE = 'incidencia_software';         // Incidencia software / plataforma
public const CAUSA_VANDALISMO          = 'vandalismo';                  // Vandalismo / daño físico
public const CAUSA_PANEL_INACCESIBLE   = 'panel_inaccesible';           // Panel inaccesible
public const CAUSA_MATERIAL            = 'material_no_disponible';      // Material no disponible
public const CAUSA_AUTORIZACION        = 'requiere_autorizacion';       // Requiere autorización / permiso previo
public const CAUSA_TERCERO             = 'requiere_apoyo_tercero';      // Requiere apoyo de tercero
public const CAUSA_OTROS               = 'otros';                       // Otros (texto libre obligatorio)
```

**Importante**: el nombre de la causa NO menciona empresas. El **actor**
responsable es campo separado.

## §1b. Catálogo `actor_responsable` (constantes)

```php
public const ACTOR_CLEAR_CHANNEL  = 'clear_channel';
public const ACTOR_INDUSTRIAL     = 'industrial';
public const ACTOR_CRTM           = 'crtm';
public const ACTOR_AYUNTAMIENTO   = 'ayuntamiento';
public const ACTOR_OPERADOR_SIM   = 'operador_sim';
public const ACTOR_PROVEEDOR      = 'proveedor';
public const ACTOR_INTERNO_WINFIN = 'interno_winfin';
public const ACTOR_OTROS          = 'otros';
```

`actor_notas` (varchar 200 nullable) permite especificar persona/empresa
concreta (p.ej. "Industrial - Carlos M., tlf 6XX...").

## §2. Schema migration

Nueva tabla `lv_derivacion`:

```sql
id                       BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
lv_ruta_dia_item_id      BIGINT UNSIGNED NOT NULL  -- FK CASCADE on item delete
tipo_causa               VARCHAR(40) NOT NULL
causa_otros_texto        VARCHAR(500) NULL          -- required if tipo_causa='otros'
actor_responsable        VARCHAR(40) NOT NULL
actor_notas              VARCHAR(200) NULL
notas_derivacion         TEXT NULL
fecha_derivacion         DATETIME NOT NULL
derivado_por_user_id     BIGINT UNSIGNED NULL       -- FK lv_users SET NULL
status                   VARCHAR(30) NOT NULL DEFAULT 'pendiente_tercero'
fecha_resolucion         DATETIME NULL
resuelto_notas           TEXT NULL
resuelto_por_user_id     BIGINT UNSIGNED NULL       -- FK lv_users SET NULL
created_at               TIMESTAMP NULL
updated_at               TIMESTAMP NULL

INDEX  (status)
INDEX  (tipo_causa)
INDEX  (actor_responsable)
INDEX  (fecha_derivacion)
UNIQUE (lv_ruta_dia_item_id, status) WHERE status IN ('pendiente_tercero','en_curso')
  -- Implementación: índice no-único en MySQL + check en service. Comentar en migration.
```

**Status enum (constants en model)**:

```php
public const STATUS_PENDIENTE_TERCERO    = 'pendiente_tercero';
public const STATUS_EN_CURSO             = 'en_curso';
public const STATUS_RESUELTO_EXTERNO     = 'resuelto_externo';
public const STATUS_DEVUELTO_A_RUTA      = 'devuelto_a_ruta';
public const STATUS_CANCELADA            = 'cancelada';
public const STATUSES_ABIERTAS = [self::STATUS_PENDIENTE_TERCERO, self::STATUS_EN_CURSO];
public const STATUSES_CERRADAS = [self::STATUS_RESUELTO_EXTERNO, self::STATUS_DEVUELTO_A_RUTA, self::STATUS_CANCELADA];
```

**Modificación a `LvRutaDiaItem`**: añadir constante `STATUS_DERIVADO = 'derivado'`
y actualizar `STATUSES` array. Cuando un item tiene derivación abierta, su
status interno pasa a `derivado`. **NO** modificar el dashboard técnico
existente — el filtro actual (status='pendiente') ya excluye `derivado`
implícitamente, pero **escribir test que confirme**.

## §3. Models

### `App\Models\LvDerivacion`

- Constantes catálogos arriba (`CAUSA_*`, `ACTOR_*`, `STATUS_*`).
- BelongsTo: `item()` → `LvRutaDiaItem`.
- BelongsTo: `derivadoPor()` → `User` (lv_users).
- BelongsTo: `resueltoPor()` → `User` nullable.
- Scopes: `scopeAbiertas`, `scopeCerradas`, `scopePorActor`.
- `isAbierta(): bool`, `isCerrada(): bool`.
- `requiereCausaOtrosTexto(): bool` (true si tipo_causa='otros').
- Cast `fecha_derivacion`, `fecha_resolucion` a datetime.

### `App\Models\LvRutaDiaItem` (extender)

- Constante nueva `STATUS_DERIVADO = 'derivado'` + en arrays.
- HasOne: `derivacionAbierta()` → `LvDerivacion` whereIn(STATUSES_ABIERTAS).
- HasMany: `derivaciones()` → `LvDerivacion` (historial).
- Helper: `tieneDerivacionAbierta(): bool`.

## §4. Service `App\Services\DerivacionService`

Responsable de transiciones de estado. Transactional.

```php
public function derivar(LvRutaDiaItem $item, array $data, User $admin): LvDerivacion;
public function resolverExternamente(LvDerivacion $deriv, array $data, User $admin): LvDerivacion;
public function devolverARuta(LvDerivacion $deriv, array $data, User $admin): LvDerivacion;
public function cancelar(LvDerivacion $deriv, array $data, User $admin): LvDerivacion;
```

### `derivar()` reglas

- Validar: `tipo_causa` en CAUSAS, `actor_responsable` en ACTORES.
- Si `tipo_causa='otros'` → `causa_otros_texto` REQUIRED non-empty.
- Si item ya tiene derivación abierta → throw `DomainException("Item ya tiene derivación abierta")`.
- Si item.status NOT IN ('pendiente', 'cerrado') → throw `DomainException`.
- En transacción:
  - Crear `LvDerivacion` con status='pendiente_tercero', fecha_derivacion=now, derivado_por_user_id=$admin->id.
  - Actualizar `item.status = 'derivado'`.
- Return derivación creada.

### `resolverExternamente()` reglas

- Validar derivación.isAbierta() else throw.
- En transacción: status='resuelto_externo', fecha_resolucion=now, resuelto_por_user_id=$admin->id, resuelto_notas=$data['notas'].
- Item permanece status='derivado' (cierre semántico — el tercero resolvió, no requiere re-visita).
- **NO** crear cadena legacy aquí (no es revisión técnica de Winfin).

### `devolverARuta()` reglas

- Validar derivación.isAbierta() else throw.
- En transacción: status='devuelto_a_ruta', fecha_resolucion=now, etc.
- Item.status volver a `pendiente` (vuelve a aparecer en lista técnico carry over).

### `cancelar()` reglas

- Validar derivación.isAbierta() else throw.
- En transacción: status='cancelada'.
- Item.status volver a `pendiente`.

## §5. Filament Resource `LvDerivacionResource`

- Slug: `derivaciones`.
- NavigationGroup: `Planificación`.
- NavigationLabel: `Derivaciones`.
- NavigationSort: después de Decisiones del día y Rutas del día.
- ModelLabel singular: `derivación`. Plural: `derivaciones`.
- Badge nav count = `LvDerivacion::abiertas()->count()`.

### Tabla columns

- `fecha_derivacion` formateada.
- `item.lvAveriaIcca.panel_id_sgip` o `item.lvRevisionPendiente.piv_id` resuelto (similar 12f).
- `tipo_causa` badge con label legible (`getCausaLabel()` helper).
- `actor_responsable` badge primary.
- `actor_notas` truncada 30 chars.
- `status` badge color (warning=pendiente_tercero, info=en_curso, success=resuelto_externo, gray=devuelto_a_ruta, danger=cancelada).
- `derivadoPor.name`.

### Filtros

- SelectFilter `status` con `->query()` defensivo (lección 12f). Default: abiertas.
- SelectFilter `tipo_causa`.
- SelectFilter `actor_responsable`.
- DatePicker `fecha_derivacion` from/to.

### Actions

- Header action **"Nueva derivación"** (modal):
  - Step 1: select `LvRutaDiaItem` (paginado, filtrado por status IN ['pendiente','cerrado']).
  - Step 2: form con tipo_causa, causa_otros_texto (visible solo si tipo='otros'), actor_responsable, actor_notas, notas_derivacion.
  - Submit → llama `DerivacionService::derivar()`.
- Per-row actions (visibles según status):
  - **Resolver externamente** (si abierta): notas required, llama `resolverExternamente`.
  - **Devolver a ruta** (si abierta): notas required, llama `devolverARuta`.
  - **Cancelar** (si abierta): notas required, llama `cancelar`.
  - **Ver detalle** (siempre): slideOver con todos los campos + link al item original.

### Action en `LvRutaDiaResource > ItemsRelationManager`

- Per-row action **"Derivar"** visible si `item.status IN ('pendiente','cerrado')` y NO tiene derivación abierta.
- Modal mismo que header action arriba (reusar form schema).

## §6. Tests Pest (mínimos obligatorios)

### Migration test
- Tabla `lv_derivacion` creada con columnas correctas + indexes + FKs físicas SET NULL en derivado_por/resuelto_por, CASCADE en item.

### Model tests (`LvDerivacion`)
- Relationship `item()` resuelve correctamente.
- Scope `abiertas` filtra correctamente.
- Constants catálogos no vacíos.
- `requiereCausaOtrosTexto()` true solo si tipo='otros'.

### `LvRutaDiaItem` extension test
- `tieneDerivacionAbierta()` true solo cuando hay derivación con status IN abiertas.
- `derivacionAbierta()` HasOne devuelve la correcta.
- Constante `STATUS_DERIVADO` añadida sin romper tests previos.

### Service tests (`DerivacionService`)
- `derivar()` happy path → crea derivación + actualiza item.status.
- `derivar()` con tipo='otros' sin causa_otros_texto → ValidationException.
- `derivar()` sobre item ya derivado → DomainException.
- `derivar()` sobre item con status='derivado' → DomainException.
- `resolverExternamente()` happy → status correcto, item permanece derivado.
- `devolverARuta()` happy → status correcto, item.status='pendiente'.
- `cancelar()` happy → status correcto, item.status='pendiente'.
- Cualquier transición sobre derivación cerrada → DomainException.
- Idempotencia: re-derivar item devuelto a ruta crea **nueva** derivación (historial preservado, derivacion previa permanece como devuelto_a_ruta).

### Tests prod-shaped (sección 5 checklist)
- Test "derivar item con tipo='otros' y causa_otros_texto vacío string ('') → ValidationException" (no solo null).
- Test "actor_notas con caracteres acentuados (Pozuelo de Alarcón) se persiste correctamente UTF-8".
- Test "Resource list con 100 derivaciones renderiza sin N+1" — usar `DB::listen` o `assertQueryCountLessThan`.

### Filament Resource tests (best effort)
- Page list renderiza 200.
- Header action "Nueva derivación" form schema válido.
- Bulk actions / per-row actions presentes (no crash al mount).

### Test importante de no-regresión 12g
- Dashboard técnico **NO** muestra items con status='derivado'.
- Dashboard técnico muestra correctamente items con status='pendiente' aunque hayan tenido derivaciones cerradas previas.

## §7. Smoke local OBLIGATORIO antes del commit final

Antes de cerrar el commit:

1. `php artisan migrate` (local SQLite).
2. `php artisan serve` y dejar levantado.
3. `curl -sI http://localhost:8000/admin/derivaciones` → 200 ó 302 (login). NUNCA 500.
4. Login admin manual + click "Nueva derivación" → modal abre sin error.
5. (Opcional but ideal) Crear seed local con 1 item ficticio + derivación + verify list renderiza.
6. `pest --parallel` 100% verde.
7. `vendor/bin/pint --test` clean.

Si algún paso falla, **NO commit**, fix antes.

## §8. RGPD y restricciones inviolables

- Cero export de campos RGPD del técnico (ya estamos lejos del PWA, pero no introduzcas accidentalmente lookups que expongan DNI/NSS/teléfono al cliente).
- `actor_notas` puede contener teléfonos de externos — eso es OK porque es interno admin, NO se expone al técnico ni a cliente final.
- No tocar prod. No `migrate` contra SiteGround.
- Sin SHA1 sin sal. Sin queries raw con concatenación.

## §9. Reporte final esperado

Al terminar, generar un mensaje de cierre en formato:

```
## Reporte Bloque 12h

**Branch**: bloque-12h-cierre-admin-derivaciones
**Commits**: <lista>
**PR**: <url>

**Schema**:
- Migration X aplicada local (✓/✗)
- Tabla lv_derivacion + indexes + FKs

**Models**: LvDerivacion + extensión LvRutaDiaItem
**Service**: DerivacionService con 4 métodos transactional
**Resource**: LvDerivacionResource + nav badge + 3 filtros + 4 actions
**Action en relation manager 12f**: "Derivar" añadida

**Tests**: <N> nuevos. Suite total: <N> verde. Pint clean.

**Smoke local ejecutado**:
- migrate ✓
- curl /admin/derivaciones ✓
- modal "Nueva derivación" abre ✓
- (opcional) seed + render list ✓

**Pendiente smoke real prod** (responsabilidad usuario post-merge):
- Backup forensic prod cifrado pre-12h.
- Migrate aplicado vía Claude Code con --pretend + OK explícito.
- Smoke real: derivar 1 item correctivo + 1 preventivo desde admin Filament.
- Verificar dashboard técnico NO muestra items derivados.
- Cleanup transactional + revertir item.status post-smoke.

**Riesgos conocidos**:
- <si hay>
```

## §10. Fuera de scope (NO hacer en este bloque)

- Emails automáticos al actor (Bloque futuro 12h.2 si se decide).
- Portal externo para que Clear Channel/Industrial reporte resolución directamente.
- Notificaciones push en PWA cuando una derivación afecta al técnico.
- Reporte mensual de derivaciones (entra en 12i).
- Cambiar el flujo PWA actual de cierre `no_resuelto` (12g sigue siendo source of truth).

---

**Cuando termines**: pega el reporte final aquí en chat. Yo (Claude Code)
revisaré con `/qa-only` y prepararemos backup forensic + smoke real prod.
