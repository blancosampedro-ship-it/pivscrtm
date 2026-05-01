# Bloque 06b — Email fast-path en `LegacyHashGuard` (ADR-0010)

> **Cómo se usa:** copia el bloque `BEGIN PROMPT` … `END PROMPT` y pégalo en VS Code Copilot Chat (modo Agent). ~30-40 min.

---

## Objetivo

Resolver el blind spot detectado en el smoke real post-merge de Bloque 06: si la fila `lv_users` tiene un `email` distinto del email correspondiente en la tabla legacy (caso del primer admin del Bloque 05, donde elegimos `info@winfin.es` mientras `u1.email='admin@admin.com'`), el guard nunca encuentra al usuario y el login falla siempre.

**Causa raíz**: el `LegacyHashGuard::attempt()` actual hace `DB::table($legacyTable)->where('email', $email)->first()` como **paso 1**. Si el email del input no coincide con `legacy.email`, la búsqueda devuelve null y el guard sale por "credenciales inválidas" — nunca llega a comprobar `lv_users.password`.

**Fix arquitectónico**: añadir un "fast path" por email en `lv_users` (filtrado por `legacy_kind`) **antes** del lookup legacy. Si encuentra fila con bcrypt y el password match, login inmediato. Si no, fall-through al flujo original.

**Por qué esto NO viola ADR-0005**: el ADR prohibía hacer `updateOrCreate` por email (eso podría duplicar filas si el email cambia en legacy). El fast-path NO hace `updateOrCreate` — solo lee. El `updateOrCreate` por `(legacy_kind, legacy_id)` se mantiene en el flujo legacy intacto.

## Definition of Done

1. ADR-0010 nuevo en `docs/decisions/0010-auth-email-fast-path.md` documentando el fast-path, su rationale, y los escenarios cubiertos.
2. `app/Auth/LegacyHashGuard.php` con el fast-path añadido **antes** del lookup legacy. Comentario inline cita ADR-0010.
3. 4 tests nuevos en `tests/Feature/Auth/LegacyHashGuardTest.php`:
   - `email_only_in_lv_users_logs_in_via_fast_path` — cubre el caso del admin Bloque 05 (lv_users.email != legacy.email).
   - `fast_path_only_matches_with_role_hint` — cross-tabla email collision: lv_users tiene operador con email X; user intenta login como admin → fast-path no matchea → fall-through.
   - `fast_path_with_wrong_password_falls_through_to_legacy_sha1` — fast-path bcrypt falla → flujo legacy SHA1 se ejecuta → covers scenario "user cambió pwd en app vieja después de migrar".
   - `fast_path_skipped_for_users_without_bcrypt` — lv_users.password=null no debe matchear nunca el fast-path.
4. Los 14 tests existentes del Bloque 06 siguen verdes (los analizamos uno por uno: ninguno asume que el fast-path no existe).
5. `pint --test`, `pest`, `npm run build` verdes.
6. PR creado, CI 3/3 verde.
7. **Post-merge**: smoke real con `info@winfin.es` + password `uCL9FROfh3qqCUEK` → debe entrar (fast-path).

## Riesgos y mitigaciones

- **Email duplicado dentro de lv_users (mismo legacy_kind)**: 17 técnicos comparten email en `tecnico` (status memory). Si se crean varios `lv_users` con `legacy_kind='tecnico'` + mismo email pero distinto `legacy_id`, `User::where('email')->where('legacy_kind')->first()` resuelve al primero. Riesgo de auth cruzado entre técnicos homónimos.
  - Mitigación: el fast-path filtra por `legacy_kind` AND password bcrypt match. Para que un técnico A con email X entre como técnico B con email X, bcrypt(B.password) tendría que matchear el password tipeado. Es teóricamente posible (si A y B usan el mismo password) pero esa colisión existe ya en el sistema legacy y no es introducida por este fix.
  - Mitigación adicional documentada en ADR-0010: si más adelante se quiere endurecer, añadir `->orderBy('id', 'asc')` para hacer el resolve determinista (siempre el más antiguo gana).
- **Cross-tabla email collision** (info@winfin.es en `tecnico` Y `operador` — confirmado en Bloque 02): el `where('legacy_kind', $roleHint)` en el fast-path lo cubre exactamente. Operador tipeando info@winfin.es en `/admin/login` no matchea fila de operador (legacy_kind='operador').
- **Rompe semántica existente**: ningún test del Bloque 06 lo asume. Verificable test-by-test (ver fase 4 del prompt).

---

## Verificación previa

Ningún script previo. Sólo verificar que el admin del Bloque 05 sigue intacto en BD prod:

```bash
php artisan tinker --execute='
$u = \App\Models\User::find(1);
echo "id=" . $u->id . " email=" . $u->email . " legacy_kind=" . $u->legacy_kind . " legacy_id=" . $u->legacy_id . PHP_EOL;
echo "bcrypt? " . (str_starts_with($u->password, "\$2y\$") ? "yes" : "no") . PHP_EOL;
'
```

Esperado: `id=1 email=info@winfin.es legacy_kind=admin legacy_id=1`, bcrypt yes.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md (convenciones)
- CLAUDE.md (división trabajo)
- docs/decisions/0003-auth-migration.md (ADR original que vamos a amendar)
- docs/decisions/0005-user-unification.md (lookup canónico — el principio que NO violamos)
- docs/decisions/0008-auth-field-correction.md (field mapping ya implementado)
- docs/prompts/06b-auth-email-fast-path.md (este archivo)
- app/Auth/LegacyHashGuard.php (código actual al que añadiremos el fast-path)

Tu tarea: implementar el Bloque 06b — añadir email fast-path al `LegacyHashGuard` para resolver el caso lv_users.email != legacy.email. ADR-0010 documenta la decisión.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch

```bash
pwd                              # /Users/winfin/Documents/winfin-piv
git branch --show-current        # main
git rev-parse HEAD               # debe ser a287b16 (post Bloque 06)
git status --short               # vacío
./vendor/bin/pest tests/Feature/Auth/ --colors=never --compact 2>&1 | tail -5
```

Los 14 tests de Bloque 06 deben estar verdes antes de empezar. Si no, AVISA.

```bash
git checkout -b bloque-06b-auth-email-fast-path
```

PARA: "Branch creada. ¿Procedo a Fase 1 (ADR-0010)?"

## FASE 1 — Escribir ADR-0010

Crea `docs/decisions/0010-auth-email-fast-path.md`:

```markdown
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

// FAST PATH (ADR-0010): si lv_users tiene fila para este (email, legacy_kind)
// con bcrypt válido, no necesitamos consultar la tabla legacy. Cubre el caso
// lv_users.email != legacy.email (admin Bloque 05) y reduce un query en el
// happy path post-migración.
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
- **`updateOrCreate` por email + (legacy_kind)`** — descartado: re-introduce el bug que ADR-0005 ya eliminó.

## Consequences

**Positivas:**
- Login del admin Bloque 05 funciona sin tocar BD legacy ni revertir su email preferido.
- Happy path post-migración usa 1 query menos (no consulta legacy si lv_users ya existe).
- Compatibilidad total con todos los tests obligatorios del Bloque 06.
- Soporte natural para futuros cambios de email vía Filament profile.

**Negativas:**
- Una rama más en el código del guard. Mitigada con comentario que cita este ADR.
- Email duplicado dentro de `lv_users` (mismo `legacy_kind`) → first-match no determinista. Aceptable porque la colisión ya existe en legacy (17 técnicos homónimos en prod) y la auth solo procede si bcrypt acierta. Si necesitamos endurecer, añadir `->orderBy('id', 'asc')` (decisión deferida).
```

PARA: "Fase 1 completa: ADR-0010 escrito. ¿Procedo a Fase 2 (modificar LegacyHashGuard)?"

## FASE 2 — Añadir fast-path al guard

Lee `app/Auth/LegacyHashGuard.php` actual. Localiza el bloque que empieza con:

```php
$meta = self::TABLE_META[$roleHint];

// 1. Resolver legacy SIEMPRE primero. Fuente de verdad de la identidad.
```

Inserta el fast-path **inmediatamente antes** de ese comentario y **después** del bloque de rate-limit. La sección final queda así:

```php
        $meta = self::TABLE_META[$roleHint];

        // 0. Email fast-path (ADR-0010). Si lv_users tiene fila para este
        //    (email, legacy_kind) con bcrypt válido, autenticamos sin consultar
        //    la tabla legacy. Cubre el caso lv_users.email != legacy.email
        //    (p. ej. admin del Bloque 05) y ahorra un query en el happy path
        //    post-migración. NO escribe — la escritura sigue siendo updateOrCreate
        //    por (legacy_kind, legacy_id) en el flujo legacy de abajo.
        $fastPathUser = User::query()
            ->where('email', $email)
            ->where('legacy_kind', $roleHint)
            ->whereNotNull('password')
            ->first();

        if ($fastPathUser !== null && Hash::check($password, $fastPathUser->password)) {
            $this->rateLimiter->clear($rlKey);
            Auth::login($fastPathUser);

            return true;
        }

        // 1. Resolver legacy SIEMPRE primero. Fuente de verdad de la identidad.
        $legacy = $this->db->table($meta['table'])
            ->where('email', $email)
            ->first();
```

El resto del archivo queda intacto. Verifica con `git diff app/Auth/LegacyHashGuard.php` que SOLO añadiste líneas (no modificaste el flujo legacy).

PARA: "Fase 2 completa: fast-path añadido. ¿Procedo a Fase 3 (tests)?"

## FASE 3 — Tests para el fast-path

Añade los 4 tests al final de `tests/Feature/Auth/LegacyHashGuardTest.php` (antes del último `});` o después del bloque de tests ADR-0008):

```php
// ---------- Tests email fast-path (ADR-0010, Bloque 06b) ----------

it('email_only_in_lv_users_logs_in_via_fast_path', function () {
    // Caso admin Bloque 05: lv_users.email != legacy.email.
    seedU1(userId: 400, email: 'legacy-email@a.a', plainPassword: 'irrelevante');
    User::create([
        'legacy_kind' => 'admin',
        'legacy_id' => 400,
        'email' => 'preferred@b.b',                  // distinto de u1.email
        'name' => 'Admin Pref',
        'password' => Hash::make('bcrypt-pwd'),
        'legacy_password_sha1' => null,
        'lv_password_migrated_at' => now(),
    ]);

    expect(guard()->attempt('preferred@b.b', 'bcrypt-pwd', 'admin', makeRequest()))->toBeTrue();
    expect(auth()->user()->id)->toBe(User::where('legacy_id', 400)->value('id'));
});

it('fast_path_only_matches_with_role_hint', function () {
    // Cross-tabla email collision: lv_users tiene operador con email X;
    // user intenta login como admin → fast-path NO matchea (legacy_kind filter)
    // → fall-through al legacy lookup (que tampoco encuentra admin con ese email).
    seedOperador(operadorId: 401, email: 'shared@x.x', plainPassword: 'op-pwd');
    User::create([
        'legacy_kind' => 'operador',
        'legacy_id' => 401,
        'email' => 'shared@x.x',
        'name' => 'Operador',
        'password' => Hash::make('op-pwd'),
        'legacy_password_sha1' => null,
        'lv_password_migrated_at' => now(),
    ]);

    // Login como admin con el email del operador → debe fallar.
    expect(guard()->attempt('shared@x.x', 'op-pwd', 'admin', makeRequest()))->toBeFalse();
    expect(auth()->check())->toBeFalse();

    // Login como operador con las creds correctas → debe entrar.
    expect(guard()->attempt('shared@x.x', 'op-pwd', 'operador', makeRequest()))->toBeTrue();
});

it('fast_path_with_wrong_password_falls_through_to_legacy_sha1', function () {
    // Escenario: usuario migró a la nueva app, después volvió a la vieja y cambió
    // password allí. lv_users tiene bcrypt(OLD), legacy tiene SHA1(NEW).
    seedU1(userId: 402, email: 'admin402@a.a', plainPassword: 'NEW');
    User::create([
        'legacy_kind' => 'admin',
        'legacy_id' => 402,
        'email' => 'admin402@a.a',
        'name' => 'Admin402',
        'password' => Hash::make('OLD'),
        'legacy_password_sha1' => null,
        'lv_password_migrated_at' => now()->subDay(),
    ]);

    // Login con password NEW. Fast-path: bcrypt(NEW) vs bcrypt(OLD) → fail.
    // Fall-through: legacy → SHA1 OK → updateOrCreate refresca bcrypt(NEW).
    expect(guard()->attempt('admin402@a.a', 'NEW', 'admin', makeRequest()))->toBeTrue();

    $u = User::where('legacy_kind', 'admin')->where('legacy_id', 402)->firstOrFail();
    expect(Hash::check('NEW', $u->password))->toBeTrue('bcrypt actualizado por SHA1 fallback');
});

it('fast_path_skipped_for_users_without_bcrypt', function () {
    // lv_users en estado pre-migración (password=null) NO debe matchear el fast-path.
    // Debe caer al flujo SHA1 normal.
    seedU1(userId: 403, email: 'admin403@a.a', plainPassword: 'sha-pwd');
    User::create([
        'legacy_kind' => 'admin',
        'legacy_id' => 403,
        'email' => 'admin403@a.a',
        'name' => 'Admin403',
        'password' => null,                          // pre-migración
        'legacy_password_sha1' => sha1('sha-pwd'),
        'lv_password_migrated_at' => null,
    ]);

    expect(guard()->attempt('admin403@a.a', 'sha-pwd', 'admin', makeRequest()))->toBeTrue();

    $u = User::where('legacy_kind', 'admin')->where('legacy_id', 403)->firstOrFail();
    expect($u->password)->not->toBeNull();
    expect(Hash::check('sha-pwd', $u->password))->toBeTrue();
    expect($u->legacy_password_sha1)->toBeNull('SHA1 borrado tras rehash');
});
```

Corre todos los tests del guard (existentes + nuevos):

```bash
./vendor/bin/pest tests/Feature/Auth/ --colors=never --compact 2>&1 | tail -30
```

**18 tests verdes esperados** (14 existentes + 4 nuevos). Si alguno de los 14 existentes falla, AVISA con el error exacto — necesitamos entender qué asunción del fast-path lo rompe antes de seguir.

PARA: "Fase 3 completa: 18 tests verdes. ¿Procedo a Fase 4 (smoke + commits + PR)?"

## FASE 4 — Smoke total + commits + PR

```bash
./vendor/bin/pint --test 2>&1 | tail -5
./vendor/bin/pest --colors=never --compact 2>&1 | tail -10
npm run build 2>&1 | tail -3
```

Si pint reporta cambios, corre `./vendor/bin/pint` y commitea como `style:` aparte.

Stage explícito por archivo. Estructura:

1. `docs: add Bloque 06b prompt + ADR-0010 (auth email fast-path)` — `docs/prompts/06b-auth-email-fast-path.md` + `docs/decisions/0010-auth-email-fast-path.md`.
2. `feat(auth): add email fast-path to LegacyHashGuard (ADR-0010)` — `app/Auth/LegacyHashGuard.php`.
3. `test: cover email fast-path scenarios (Bloque 06b)` — los 4 tests añadidos a `tests/Feature/Auth/LegacyHashGuardTest.php`.

Smoke final + push:

```bash
./vendor/bin/pest --colors=never --compact 2>&1 | tail -5
git push -u origin bloque-06b-auth-email-fast-path
```

Crea PR:

```bash
gh pr create \
  --base main \
  --head bloque-06b-auth-email-fast-path \
  --title "Bloque 06b — Email fast-path en LegacyHashGuard (ADR-0010)" \
  --body "$(cat <<'BODY'
## Resumen

Añade un email fast-path en `LegacyHashGuard::attempt()` que resuelve el caso `lv_users.email != legacy.email` detectado en el smoke real post-Bloque 06 con el admin del Bloque 05 (`lv_users.email='info@winfin.es'` vs `u1.email='admin@admin.com'`).

ADR-0010 documenta la decisión y por qué no viola el principio de ADR-0005 (lookup canónico).

## Qué entra

- `docs/decisions/0010-auth-email-fast-path.md` — ADR amendando ADR-0003.
- `app/Auth/LegacyHashGuard.php` — fast-path añadido entre rate-limit y legacy lookup. Solo lee. La escritura sigue siendo updateOrCreate por (legacy_kind, legacy_id).
- 4 tests nuevos en `LegacyHashGuardTest.php`:
  - `email_only_in_lv_users_logs_in_via_fast_path` (caso admin Bloque 05)
  - `fast_path_only_matches_with_role_hint` (cross-tabla collision defense)
  - `fast_path_with_wrong_password_falls_through_to_legacy_sha1` (cambio pwd en app vieja después de migrar)
  - `fast_path_skipped_for_users_without_bcrypt` (pre-migración path intacto)

## Compatibilidad

Los 14 tests existentes del Bloque 06 siguen verdes — el fast-path se inserta como camino alternativo, no modifica el flujo legacy.

## Post-merge

Smoke real al admin Bloque 05: login con `info@winfin.es` + password ya generado debería entrar en `/admin/login`.

## CI esperado

3/3 jobs verde (PHP 8.2, PHP 8.3, Vite build).
BODY
)"

sleep 8
PR_NUM=$(gh pr list --head bloque-06b-auth-email-fast-path --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ Qué he hecho:
   - ADR-0010 documenta el fast-path + por qué no viola ADR-0005.
   - LegacyHashGuard con email fast-path antes del lookup legacy.
   - 4 tests nuevos cubriendo todos los escenarios.
   - 18 tests Auth/ verdes (14 + 4). Todo el suite pest verde.
   - Pint clean. Build OK.
   - 3 commits Conventional Commits.
   - PR #N: [URL].
   - CI 3/3 verde.

⏳ Qué falta:
   - (Manual, post-merge) Smoke real con info@winfin.es + uCL9FROfh3qqCUEK en http://127.0.0.1:8000/admin/login.
   - Bloque 07 — Filament resource para Piv.

❓ Qué necesito del usuario:
   - Confirmar PR.
   - Mergear (Rebase and merge sugerido).
   - Tras merge, smoke real.
```

NO mergees el PR.

END PROMPT
```

---

## Después de Bloque 06b

1. Smoke manual con admin Bloque 05 — debe entrar.
2. **Bloque 07** — Filament resource para `Piv` (eager loading + validación municipio según ADR-0007).
