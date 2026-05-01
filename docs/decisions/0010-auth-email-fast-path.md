# 0010 — Email fast-path en `LegacyHashGuard`

- **Status**: Accepted
- **Date**: 2026-05-01
- **Amends**: ADR-0003 (auth migration SHA1→bcrypt). ADR-0005 (user unification) sin cambios — su principio del lookup canónico se preserva.

## Context

El `LegacyHashGuard` implementado en Bloque 06 (siguiendo ADR-0003) hace como **paso 1** la resolución de la fila legacy por email:

```php
$legacy = DB::table($meta['table'])->where('email', $email)->first();
if (! $legacy) return false;
```

Esto asume implícitamente que `lv_users.email = legacy.email` para todo usuario migrado. El asumido se rompe en al menos un caso real:

**Caso del primer admin (Bloque 05, 1 may 2026):** se creó `lv_users` con email `info@winfin.es` (preferencia del usuario) mientras `u1.email='admin@admin.com'` (valor histórico legacy). En el primer smoke real post-Bloque 06, login con `info@winfin.es` falló porque `u1.where('email', 'info@winfin.es')` devolvió null y el guard nunca llegó al check de bcrypt en `lv_users`.

Caso futuro probable: usuarios que entren a la app nueva via lazy-create y luego cambien su email en Filament. Sus `lv_users.email` divergen de `legacy.email` y vuelven a caer en el mismo bug.

## Decision

Añadir un "**email fast-path**" en `LegacyHashGuard::attempt()` **antes** del lookup legacy:

```php
// Después del rate limit, ANTES del lookup legacy:

$user = User::where('email', $email)
    ->where('legacy_kind', $roleHint)
    ->whereNotNull('password')
    ->first();

if ($user !== null && Hash::check($password, $user->password)) {
    $this->rateLimiter->clear($rlKey);
    Auth::login($user);
    return true;
}

// (Fall-through al lookup legacy original — código intacto).
```

### Por qué NO viola ADR-0005

ADR-0005 §2 prohibió **`updateOrCreate` por email** porque podría duplicar filas si el email cambia en legacy. El fast-path **solo lee** — no escribe. La escritura sigue siendo `updateOrCreate(['legacy_kind', 'legacy_id'], [...])` en el flujo legacy intacto.

### Por qué el filtro `legacy_kind` es obligatorio

Cross-tabla email collision: el email `info@winfin.es` aparece en `tecnico` Y `operador` legacy (verificado en Bloque 02). Si el fast-path no filtrara por `legacy_kind`, un operador podría entrar al panel admin escribiendo el email del admin (asumiendo que sus passwords colisionaran, escenario raro pero real). El filtro `where('legacy_kind', $roleHint)` lo previene a coste cero.

### Por qué `whereNotNull('password')`

Si una fila `lv_users` está en estado pre-migración (`password = null`, `legacy_password_sha1 != null`), el `Hash::check($plain, null)` falla. Filtrar antes evita el ruido y deja claro que el fast-path solo cubre el camino post-migración.

## Escenarios cubiertos

| Escenario | Antes (Bloque 06) | Después (Bloque 06b) |
|---|---|---|
| Login normal post-migración (lv_users.email == legacy.email) | OK (vía legacy + canonical lookup) | OK (vía fast-path, 1 query menos) |
| Primera vez login (no hay lv_users todavía) | OK (legacy → SHA1 → updateOrCreate) | OK idem (fast-path skipped, fall-through) |
| User cambió password en app vieja después de migrar | OK (legacy → SHA1 → updateOrCreate refresca bcrypt) | OK idem (fast-path bcrypt fails → fall-through → SHA1 → refresh) |
| User cambió email en app vieja después de migrar | OK (legacy → canonical (legacy_kind, legacy_id) → updateOrCreate refresca email) | OK idem (fast-path no encuentra el email nuevo en lv_users → fall-through → legacy → canonical → refresh) |
| **lv_users.email != legacy.email (caso admin Bloque 05)** | **FAIL** (legacy lookup no encuentra) | **OK** (fast-path resuelve) |
| Wrong password en cualquier escenario | Fail + rate limit hit | Fail + rate limit hit (idem) |

## Considered alternatives

- **Cambiar `u1.email` para alinear con `lv_users`** — descartado: requiere DML en tabla legacy, riesgo desconocido de afectar `login.php` viejo si autentica por email.
- **Cambiar `lv_users.email` para alinear con `u1`** — descartado: pierde la preferencia del usuario, no resuelve el caso futuro de cambios de email vía Filament.
- **Lookup `lv_users` por email SIN filtrar por `legacy_kind`** — descartado: cross-tabla collision permite auth cruzado entre roles. Inaceptable.
- **`updateOrCreate` por email + (legacy_kind)** — descartado: re-introduce el bug que ADR-0005 ya eliminó.

## Consequences

**Positivas:**
- Login del admin Bloque 05 funciona sin tocar BD legacy ni revertir su email preferido.
- Happy path post-migración usa 1 query menos (no consulta legacy si lv_users ya existe).
- Compatibilidad total con todos los tests obligatorios del Bloque 06.
- Soporte natural para futuros cambios de email vía Filament profile.

**Negativas:**
- Una rama más en el código del guard. Mitigada con comentario que cita este ADR.
- Email duplicado dentro de `lv_users` (mismo `legacy_kind`) → first-match no determinista. Aceptable porque la colisión ya existe en legacy (17 técnicos homónimos en prod) y la auth solo procede si bcrypt acierta. Si necesitamos endurecer, añadir `->orderBy('id', 'asc')` (decisión deferida).
