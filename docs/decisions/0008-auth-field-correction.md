# 0008 — Corrección de nombres de columnas auth en tablas legacy

- **Status**: Accepted
- **Date**: 2026-04-30
- **Amends**: ADR-0003 (auth migration SHA1→bcrypt) y ADR-0005 (user unification).

## Context

ADR-0003 §"Decision" presenta pseudocódigo del `LegacyHashGuard::attempt()` que asume que las tablas `tecnico` y `operador` tienen una columna `password`:

```php
// Pseudocódigo de ADR-0003 — INCORRECTO
$legacy = DB::table($legacyTable)->where('email', $credentials['email'])->first();
if (!hash_equals(sha1($credentials['password']), strtolower($legacy->password))) {
    return false;
}
```

ADR-0005 §"Estrategia" describe el flujo lazy de creación de `lv_users` con lookup por `(legacy_kind, legacy_id)`, asumiendo que `legacy_id` se obtiene del PK de cada tabla origen.

Tras conectar a producción 2026-04-30 (Bloque 02), `INFORMATION_SCHEMA` revela:

| Tabla | Columna password real | Columna PK real |
|-------|----------------------|-----------------|
| `tecnico` | **`clave`** (no `password`) | `tecnico_id` |
| `operador` | **`clave`** (no `password`) | `operador_id` |
| `u1` | `password` (correcto) | **`user_id`** (no `u1_id`) |

Hay también una columna `usuario` en `tecnico` y `operador` que la app vieja usa como login (paralelo al `email`). No la asumimos en el guard nuevo (login se resuelve por `email` por ADR-0005), pero existe.

Si el guard se implementa según el pseudocódigo de ADR-0003 sin esta corrección:

- En `tecnico`/`operador`, `$legacy->password` devuelve `NULL` (la columna no existe). `hash_equals(sha1($plain), strtolower(NULL))` es siempre false. **El login nunca funciona** para técnicos ni operadores. Bug silencioso.
- En `u1` el bug NO ocurre (la columna sí se llama `password`).

## Decision

### Pseudocódigo correcto del guard (Bloque 06)

```php
// LegacyHashGuard::attempt(array $credentials, string $roleHint, Request $request)

// Mapeo (legacy_kind => [tabla, columna PK, columna password])
$tableMeta = match ($roleHint) {
    'admin'    => ['table' => 'u1',       'pk' => 'user_id',     'password_col' => 'password'],
    'tecnico'  => ['table' => 'tecnico',  'pk' => 'tecnico_id',  'password_col' => 'clave'],
    'operador' => ['table' => 'operador', 'pk' => 'operador_id', 'password_col' => 'clave'],
};

$legacy = DB::table($tableMeta['table'])
    ->where('email', $credentials['email'])
    ->first();

if (! $legacy) {
    RateLimiter::hit($rlKey, 60);
    return false;
}

// Compare timing-safe contra el campo real (clave o password según tabla)
$legacyHash = $legacy->{$tableMeta['password_col']};
if (! hash_equals(sha1($credentials['password']), strtolower($legacyHash ?? ''))) {
    RateLimiter::hit($rlKey, 60);
    return false;
}

// Lookup canónico por (legacy_kind, legacy_id)
//   legacy_id = $legacy->{$tableMeta['pk']}
$legacyId = $legacy->{$tableMeta['pk']};

$user = LvUser::where('legacy_kind', $roleHint)
    ->where('legacy_id', $legacyId)
    ->first();

// ... resto del flow (bcrypt check, updateOrCreate, etc.)
```

La clave del cambio: **el guard NUNCA hardcodea `password` ni `tecnico_id`** — los toma del mapeo `$tableMeta` para cada rol.

### Modelos Eloquent (Bloque 03)

Los modelos legacy `Tecnico`, `Operador`, `U1` deben declarar las columnas reales:

```php
class Tecnico extends Model
{
    protected $table = 'tecnico';
    protected $primaryKey = 'tecnico_id';
    public $timestamps = false;
    /** Password column en legacy: 'clave' (NO 'password'). Ver ADR-0008. */
    // No exponemos 'clave' como atributo accessible — solo se lee via DB::table en el guard.
    protected $hidden = ['clave'];
    // ...
}

class Operador extends Model
{
    protected $table = 'operador';
    protected $primaryKey = 'operador_id';
    public $timestamps = false;
    protected $hidden = ['clave'];
    // ...
}

class U1 extends Model
{
    protected $table = 'u1';
    protected $primaryKey = 'user_id';  // EXCEPCIÓN a convención <tabla>_id
    public $timestamps = false;
    protected $hidden = ['password'];
    // ...
}
```

### Tests obligatorios actualizados (Bloque 06)

Añadir a la tabla "Tests obligatorios por bloque" en `.github/copilot-instructions.md`:

- `legacy_login_uses_correct_password_column` — verifica que el guard lee de `tecnico.clave` para `roleHint='tecnico'`, `operador.clave` para `roleHint='operador'`, y `u1.password` para `roleHint='admin'`. Test parametrizado.
- `u1_user_id_pk_works_with_lv_users_lookup` — verifica que el lookup `(legacy_kind='admin', legacy_id=<u1.user_id>)` resuelve correctamente, no requiere column llamada `u1_id`.

## Considered alternatives

- **Renombrar `tecnico.clave` y `operador.clave` a `password` via ALTER TABLE** — descartado: viola regla #2 (no modificar schema legacy). Y rompería la app vieja que sigue funcionando con esos nombres.
- **Vista MySQL `legacy_auth` que abstrae las diferencias** (`CREATE VIEW legacy_auth AS SELECT ... AS password FROM tecnico UNION ...`) — descartado: complejidad innecesaria. Una vista por rol o una vista unificada con discriminator. Operacionalmente más frágil que un mapeo en código PHP.
- **Mapeo dinámico via `INFORMATION_SCHEMA`** (descubrir el nombre de la columna en runtime) — descartado: micro-optimización paranoica. El nombre de la columna no va a cambiar.

## Consequences

**Positivas:**
- El guard funciona en runtime para los 3 roles. Sin esto, login de técnicos y operadores fallaría silenciosamente — el bug solo se detectaría al testar manualmente con un usuario real, NO con tests Pest si los tests usan factories que escriben en `tecnico.password` (campo inexistente, MySQL lo rechazaría de todos modos).
- Los modelos Eloquent reflejan realidad. Cualquier consulta tipo `Tecnico::where('email', $x)->first()` funciona.
- ADR-0003 + ADR-0005 quedan amendados consistentemente.

**Negativas:**
- El guard tiene un mapeo `$tableMeta` que cualquier desarrollador futuro debe entender. Mitigación: comentarios explícitos + tests obligatorios.
- Si en algún momento se incorporara un cuarto rol legacy (improbable), habría que extender el match. Aceptable, todas las opciones de auth lo requieren.

**Implementación**:
- Bloque 03 (Eloquent models): aplicar los modelos con `$primaryKey` correcto y `clave` (donde aplique).
- Bloque 06 (LegacyHashGuard): aplicar el mapeo `$tableMeta`. Tests obligatorios `legacy_login_uses_correct_password_column` y `u1_user_id_pk_works_with_lv_users_lookup` añadidos al DoD.
- ADR-0003 y ADR-0005 mantienen su validez estructural; este ADR-0008 los amenda solo en el detalle de naming.
