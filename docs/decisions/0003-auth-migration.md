# 0003 — Migración de contraseñas SHA1 → bcrypt (lazy)

- **Status**: Accepted
- **Date**: 2026-04-29

## Context

Las tablas legacy `u1`, `tecnico` y `operador` guardan las contraseñas en **SHA1 sin sal**. Esto es vulnerable a rainbow tables y no cumple buenas prácticas. Pedir a los 1+65+41 = 107 usuarios que reseteen su contraseña a la vez es:

- **Friction operativa**: técnicos en campo y operadores externos no leen emails de "resetea tu contraseña".
- **Riesgo de bloqueo**: si alguien no recupera, no puede operar y bloquea su flujo.

A la vez, no podemos permitirnos seguir validando contra SHA1 indefinidamente.

## Decision

**Migración lazy en el primer login exitoso de cada usuario, sin seeder ni cron de sync.**

La fila `lv_users` se **crea al vuelo** en el primer login exitoso. La estrategia completa de unificación de las tres tablas legacy (`u1`/`tecnico`/`operador`) en `lv_users` está documentada en [ADR-0005](0005-user-unification.md). Aquí el flujo concreto del guard:

```php
// LegacyHashGuard::attempt(array $credentials, string $roleHint, Request $request)
// $roleHint ∈ {'admin','tecnico','operador'} viene fijado por el subdominio/ruta.

// 0. Rate limit (5 intentos por minuto por IP+email — antes de cualquier lookup).
$rateLimitKey = 'login:'.$request->ip().'|'.strtolower($credentials['email']).'|'.$roleHint;
if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
    throw new TooManyRequestsException(RateLimiter::availableIn($rateLimitKey));
}

// 1. Resolver legacy SIEMPRE primero — la fuente de verdad es la tabla legacy.
//    No se busca por email en lv_users como vía nominal: el email puede haber
//    cambiado en la app vieja después del primer login, y eso provocaría la fuga
//    descrita en la revisión externa.
$legacyTable = match ($roleHint) {
    'admin'    => 'u1',
    'tecnico'  => 'tecnico',
    'operador' => 'operador',
};
$legacy = DB::table($legacyTable)
    ->where('email', $credentials['email'])
    ->first();

if (! $legacy) {
    RateLimiter::hit($rateLimitKey, 60);
    return false;
}

// 2. Lookup canónico en lv_users por (legacy_kind, legacy_id) — NUNCA por email.
$user = LvUser::where('legacy_kind', $roleHint)
    ->where('legacy_id', $legacy->id)
    ->first();

// 3. ¿Bcrypt OK? (vía nominal post-migración)
if ($user?->password && Hash::check($credentials['password'], $user->password)) {
    RateLimiter::clear($rateLimitKey);
    return $this->login($user);
}

// 4. Bcrypt falló o lv_users no existe todavía. Validar SHA1 legacy timing-safe.
if (! hash_equals(sha1($credentials['password']), strtolower($legacy->password))) {
    RateLimiter::hit($rateLimitKey, 60);
    return false;
}

// 5. SHA1 OK — crear o actualizar lv_users con bcrypt fresco.
//    `updateOrCreate` por (legacy_kind, legacy_id) NUNCA por email.
//    Si el email cambió en legacy, la fila existente se actualiza con el email nuevo.
$user = LvUser::updateOrCreate(
    ['legacy_kind' => $roleHint, 'legacy_id' => $legacy->id],
    [
        'email'                   => $legacy->email,
        'name'                    => $legacy->nombre_completo ?? $legacy->nombre ?? $legacy->username,
        'password'                => Hash::make($credentials['password']),
        'legacy_password_sha1'    => null,
        'lv_password_migrated_at' => now(),
    ]
);

RateLimiter::clear($rateLimitKey);
return $this->login($user);
```

**Detalles importantes:**

1. **Comparación SHA1 con `hash_equals()`** — evita timing attacks.
2. **Lookup canónico SIEMPRE por `(legacy_kind, legacy_id)`** tras resolver la fila legacy. **NUNCA por email** en `lv_users`. Si hacemos lookup por email primero y el email cambió en la app vieja después del primer login, el guard creería que es un usuario nuevo, ejecutaría `updateOrCreate` y silenciosamente sobrescribiría la fila correcta. Caso de bug detectado en review externa (29 abr 2026).
3. **Si bcrypt falla, NO devolver false directamente** — re-intentar lookup legacy. Caso real: el usuario migró a la nueva app (tiene `lv_users.password` bcrypt) pero después volvió a la vieja y allí cambió su password. El bcrypt en `lv_users` queda obsoleto. El re-lookup en legacy lo detecta y rehashea otra vez.
4. **`legacy_password_sha1` se borra al primer login OK.** No persiste el SHA1 en `lv_users` después.
5. **No hay seeder ni cron** que pre-rellene `lv_users`. Es lazy total. Justificación detallada en ADR-0005.
6. **`role-hint` por subdominio/ruta**, NO por columna en BD ni por selector en el form. Cada flujo de login (admin Filament, técnico PWA, operador PWA) sabe su rol por la URL. Ver ADR-0005 §3.
7. **Rate limit con `RateLimiter`** del propio Laravel: 5 intentos por minuto por `(IP, email, roleHint)`. Aplicado **antes** de cualquier query a BD para que el ataque sea barato de bloquear. **Crítico:** Fortify trae throttle nativo, pero solo se aplica si el guard va dentro de su pipeline (`authenticateUsing` callback). Como nuestro guard es custom y se ejecuta fuera de ese pipeline, **el throttle de Fortify no protege** y hay que añadirlo manualmente. Sin esto, los 107 SHA1 publicados en el dump SQL (incidente RGPD pendiente) son rainbow-table-eados offline y luego replayados online sin freno.
8. **Verificación previa obligatoria** (antes de Bloque 06 del roadmap): correr el SQL de ADR-0005 §4 sobre producción para detectar emails duplicados entre tablas. Si los hay, documentar y revisar caso por caso.
9. **Purga del SHA1 legacy:** tras X meses (definir en Fase 7), notificar a los usuarios "dormidos" (que nunca entraron a la nueva app y por tanto siguen sin fila en `lv_users`) para que reseteen su password en la app vieja antes del cutover.

## Considered alternatives

- **Forzar reset masivo de contraseñas** — descartado: alta fricción, alto riesgo de usuarios bloqueados.
- **Mantener SHA1 indefinidamente** — descartado: incompatible con seguridad mínima moderna y con la auditoría RGPD.
- **Doble hash en migración (bcrypt(sha1(password)))** — descartado: ofusca el problema; cualquier filtración del bcrypt aún expone el SHA1 subyacente y los usuarios siguen sin "real bcrypt".

## Consequences

**Positivas:**
- Cero fricción para el usuario: entra como siempre, sale "rehasheado".
- Migración progresiva, sin big-bang.
- Defensa en profundidad: `hash_equals` evita timing attacks; el SHA1 se borra inmediatamente tras el primer login.
- Cero código de sync (sin seeder, sin cron, sin job). El guard es la única superficie a mantener.
- El re-lookup tras bcrypt fallido cubre el caso "usuario cambió password en la app vieja después de haber migrado a la nueva".

**Negativas:**
- Los usuarios que no entren nunca al sistema nuevo siguen con SHA1 hasta la purga programada.
- Hay que coordinar la purga (Fase 7) con un aviso previo a esos usuarios "dormidos" para que reseteen.
- El código del guard tiene una rama legacy que hay que recordar quitar después de la purga.
- En el primer login de cada usuario, hay un query extra a la tabla legacy. Ocurre **una sola vez por usuario** durante toda la vida del sistema. Insignificante.
