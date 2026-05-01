# Bloque 06 — `LegacyHashGuard`: lazy SHA1→bcrypt + rate limit + canonical lookup

> **Cómo se usa:** copia el bloque `BEGIN PROMPT` … `END PROMPT` y pégalo en VS Code Copilot Chat (modo Agent). ~75-90 min. **Sesión dedicada — no mezclar con otro bloque.**

---

## Objetivo

Implementar la lógica de autenticación canónica del proyecto: un servicio `App\Auth\LegacyHashGuard` que:

1. **Resuelve la fila legacy** (`u1` / `tecnico` / `operador`) por email + role-hint.
2. **Valida la contraseña** en orden: bcrypt en `lv_users.password` → fallback a SHA1 legacy con `hash_equals()`.
3. **Rehashea lazy a bcrypt** la primera vez que el SHA1 acierta — `updateOrCreate` por `(legacy_kind, legacy_id)`, **NUNCA por email**.
4. **Aplica rate limit** 5/60 s por `(IP, email lower-cased, roleHint)` — antes de cualquier query a BD.
5. **Field mapping correcto por rol** (ADR-0008): `u1.password` / `tecnico.clave` / `operador.clave`, PKs `user_id` / `tecnico_id` / `operador_id`.

Y conectar todo eso al **login de Filament** (`/admin/login`) sustituyendo la `Login` page por una override que delega en el guard.

**Fuera de alcance:**
- Login para técnicos (`/tecnico/login`) y operadores (`/operador/login`) — esas rutas viven en sus PWAs (Bloques 11/12). El servicio queda probado para los 3 roles vía tests directos; cuando lleguen las PWAs reutilizan el mismo servicio.
- Reset de password vía email (Filament `->passwordReset()` desactivado para auth legacy, no aplica).
- Logout custom — el default de Filament basta.

## Arquitectura

```
┌─────────────────────────────────┐
│ Filament Admin Panel            │
│   /admin/login (Livewire)       │
│   App\Filament\Pages\Auth\Login │──┐
└─────────────────────────────────┘  │
                                     ▼
                          ┌──────────────────────────┐
                          │ App\Auth\LegacyHashGuard │ <- (futuro: PWA tecnico, PWA operador)
                          │   ::attempt($email,      │
                          │             $password,   │
                          │             $roleHint,   │
                          │             $request)    │
                          └──────────────────────────┘
                                     │
                          ┌──────────┴──────────┐
                          ▼                     ▼
                   RateLimiter (cache)    DB::table($legacyTable)
                                          ↓
                                          User::updateOrCreate(
                                            (legacy_kind, legacy_id),
                                            [...]
                                          )
                                          ↓
                                          Auth::login($user)
```

## Definition of Done

1. `app/Auth/LegacyHashGuard.php` con método `attempt(string $email, string $password, string $roleHint, Request $request): bool` que retorna `true`/`false` y lanza `Illuminate\Validation\ValidationException` (o equivalente) en caso de throttle.
2. Mapeo `TABLE_META` constante con (table, pk, password_col) por roleHint, tal cual ADR-0008.
3. Lookup canónico en `lv_users` por `(legacy_kind, legacy_id)` — no por email.
4. `hash_equals(sha1($plain), strtolower($legacyHash ?? ''))` para comparación SHA1 timing-safe (el `?? ''` cubre el caso `clave IS NULL`).
5. `RateLimiter` 5 intentos / 60 s. Clave: `legacy-login:{ip}|{lowercased_email}|{role}`.
6. `app/Filament/Pages/Auth/Login.php` que extiende `Filament\Pages\Auth\Login` y override `authenticate()` para delegar en el guard con role='admin'.
7. `AdminPanelProvider` usa la Login custom: `->login(\App\Filament\Pages\Auth\Login::class)`.
8. Tests Pest (mínimo 11 — los 9 obligatorios del DoD del proyecto + 2 más):
   - `legacy_login_rehashes_to_bcrypt`
   - `legacy_login_uses_hash_equals`
   - `bcrypt_fail_falls_back_to_legacy_lookup`
   - `wrong_password_never_creates_lv_user_row`
   - `lookup_canonical_by_legacy_kind_legacy_id`
   - `login_throttles_after_5_failures`
   - `successful_login_clears_rate_limit`
   - `legacy_login_uses_correct_password_column` (parametrizado: u1.password, tecnico.clave, operador.clave)
   - `u1_user_id_pk_works_with_lv_users_lookup`
   - `email_change_in_legacy_after_first_login_does_not_create_new_lv_user_row`
   - `admin_login_via_filament_uses_legacy_hash_guard` (Livewire integration)
9. Smoke manual `/admin/login` real con el admin existente (`info@winfin.es` + password generado en Bloque 05) sigue funcionando.
10. `pint --test`, `pest`, `npm run build` verdes.
11. PR creado, CI 3/3 verde.

---

## Riesgos y mitigaciones

- **Filament Login page interna cambia entre versiones**. Mitigación: extender la clase oficial y override solo `authenticate()`. El resto de la página (form schema, layout) se hereda intacto.
- **`RateLimiter` en tests usa cache**. Default del entorno testing es `array` (en memoria), se reinicia entre tests. Hay que añadir `RateLimiter::clear()` en `beforeEach` de los tests de throttle para evitar bleed entre casos.
- **`legacy_password_sha1` ya viene NULL para el admin del Bloque 05** (ese admin nació post-migración). Tests obligatorios deben usar factories o fixtures distintos que sí simulen estado pre-migración (legacy SHA1 sin lv_users.password).
- **`Hash::check()` falla silencioso si password es NULL**. Mitigación: comprobar `$user?->password !== null` antes de llamar a `Hash::check`.
- **Filament `request()` durante test Livewire**. La integración test usa `Livewire::test(...)->call('authenticate')`. Dentro, `request()` devuelve la request del test runner, OK para extraer IP (suele ser `127.0.0.1`).
- **El admin de Bloque 05 quedaría afectado si el guard tiene un bug que sobrescribe filas**. Tests de tipo "wrong_password_never_creates_lv_user_row" + "lookup_canonical" lo cubren. Pero el admin real está en BD prod — si quieres confianza extra, después de mergar Bloque 06 verificamos en tinker que la fila `lv_users.id=1` sigue intacta antes de probar login real.

---

## Verificación previa obligatoria (ya hecha en Bloque 02)

ADR-0005 §4 pidió correr SQL para detectar emails duplicados cross-tabla. Resultado documentado en status.md: 1 caso (`info@winfin.es` en `tecnico` Y `operador`), 17 within `tecnico`, 0 within `u1`. La estrategia `(legacy_kind, legacy_id)` cubre todos los casos.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md (convenciones + tests obligatorios Bloque 06)
- CLAUDE.md (división trabajo)
- docs/decisions/0003-auth-migration.md (algoritmo lazy SHA1->bcrypt — pseudocódigo amendado por ADR-0008)
- docs/decisions/0005-user-unification.md (lookup canónico, lazy creation, role-hint por ruta)
- docs/decisions/0008-auth-field-correction.md (mapping tableMeta — ESTE ES EL CORRECTO, no el pseudocódigo de 0003)
- docs/prompts/06-auth-sha1-bcrypt.md (este archivo, secciones objetivo y riesgos)

Tu tarea: implementar el LegacyHashGuard + integración en Filament admin login + tests. Sesión dedicada. ~75-90 min.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch

```bash
pwd                              # /Users/winfin/Documents/winfin-piv
git branch --show-current        # main
git rev-parse HEAD               # debe ser a954532 (post Bloque 05)
git status --short               # vacío
./vendor/bin/pest --version      # Pest 3.x
```

Si algo no encaja, AVISA y para.

```bash
git checkout -b bloque-06-legacy-hash-guard
```

PARA: "Branch creada. ¿Procedo a Fase 1 (LegacyHashGuard)?"

## FASE 1 — Implementar `App\Auth\LegacyHashGuard`

Crea `app/Auth/LegacyHashGuard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Guard custom que valida login contra los hashes legacy SHA1 (`u1.password`,
 * `tecnico.clave`, `operador.clave`) y rehashea lazy a bcrypt en la fila
 * `lv_users` correspondiente.
 *
 * Diseñado como servicio puro (no extiende SessionGuard) para poder reutilizarse
 * desde Filament (admin), Livewire/Volt (PWA tecnico) y Livewire/Volt (PWA operador).
 *
 * Algoritmo definitivo en ADR-0003 + ADR-0008. Field mapping por rol en ADR-0008.
 */
final class LegacyHashGuard
{
    /**
     * Mapeo (legacy_kind => meta de la tabla legacy correspondiente).
     * Ver ADR-0008 — los nombres reales en producción son distintos a los
     * asumidos en ADR-0003 antes de inspeccionar el schema vivo.
     */
    private const TABLE_META = [
        'admin' => [
            'table' => 'u1',
            'pk' => 'user_id',
            'password_col' => 'password',
            'name_cols' => ['username'],
        ],
        'tecnico' => [
            'table' => 'tecnico',
            'pk' => 'tecnico_id',
            'password_col' => 'clave',
            'name_cols' => ['nombre_completo', 'usuario'],
        ],
        'operador' => [
            'table' => 'operador',
            'pk' => 'operador_id',
            'password_col' => 'clave',
            'name_cols' => ['razon_social', 'responsable', 'usuario'],
        ],
    ];

    /** Máximo de intentos fallidos por (IP, email, rol) antes de bloquear. */
    public const MAX_ATTEMPTS = 5;

    /** Ventana del rate limit en segundos. */
    public const DECAY_SECONDS = 60;

    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly ConnectionInterface $db,
    ) {
    }

    /**
     * Intenta login. Devuelve true si autentica (y hace `Auth::login()`),
     * false si credenciales inválidas. Lanza ValidationException con código
     * de throttle si supera MAX_ATTEMPTS.
     */
    public function attempt(string $email, string $password, string $roleHint, Request $request): bool
    {
        if (! isset(self::TABLE_META[$roleHint])) {
            throw new \InvalidArgumentException("Rol desconocido: {$roleHint}");
        }

        $rlKey = $this->rateLimitKey($request->ip() ?? '0.0.0.0', $email, $roleHint);

        if ($this->rateLimiter->tooManyAttempts($rlKey, self::MAX_ATTEMPTS)) {
            $seconds = $this->rateLimiter->availableIn($rlKey);
            throw ValidationException::withMessages([
                'data.email' => trans('auth.throttle', ['seconds' => $seconds]),
            ]);
        }

        $meta = self::TABLE_META[$roleHint];

        // 1. Resolver legacy SIEMPRE primero. Fuente de verdad de la identidad.
        $legacy = $this->db->table($meta['table'])
            ->where('email', $email)
            ->first();

        if (! $legacy) {
            $this->rateLimiter->hit($rlKey, self::DECAY_SECONDS);
            return false;
        }

        $legacyId = (int) $legacy->{$meta['pk']};
        $legacyHash = $legacy->{$meta['password_col']} ?? '';

        // 2. Lookup canónico por (legacy_kind, legacy_id) — NUNCA por email.
        //    Si el email cambió en legacy entre logins, esta clave compuesta
        //    sigue resolviendo a la misma fila lv_users.
        $user = User::query()
            ->where('legacy_kind', $roleHint)
            ->where('legacy_id', $legacyId)
            ->first();

        // 3. Bcrypt OK -> happy path post-migración.
        if ($user !== null && $user->password !== null && Hash::check($password, $user->password)) {
            $this->rateLimiter->clear($rlKey);
            Auth::login($user);
            return true;
        }

        // 4. Bcrypt falló o todavía no había fila lv_users. Validar contra SHA1
        //    legacy timing-safe. Cubre tanto el primer login (lazy create) como
        //    el caso "el usuario cambió password en la app vieja después de migrar".
        if (! hash_equals(sha1($password), strtolower((string) $legacyHash))) {
            $this->rateLimiter->hit($rlKey, self::DECAY_SECONDS);
            return false;
        }

        // 5. SHA1 OK. Crear o actualizar lv_users con bcrypt fresco.
        //    updateOrCreate por (legacy_kind, legacy_id). Si el email cambió
        //    en legacy, la fila existente se actualiza con el email nuevo.
        $user = User::updateOrCreate(
            ['legacy_kind' => $roleHint, 'legacy_id' => $legacyId],
            [
                'email' => $legacy->email,
                'name' => $this->resolveName($legacy, $meta['name_cols']) ?? $legacy->email,
                'password' => $password, // cast 'hashed' aplica bcrypt
                'legacy_password_sha1' => null,
                'lv_password_migrated_at' => now(),
            ]
        );

        $this->rateLimiter->clear($rlKey);
        Auth::login($user);
        return true;
    }

    private function rateLimitKey(string $ip, string $email, string $roleHint): string
    {
        return 'legacy-login:'.$ip.'|'.strtolower($email).'|'.$roleHint;
    }

    private function resolveName(object $legacy, array $cols): ?string
    {
        foreach ($cols as $col) {
            if (! empty($legacy->{$col} ?? null)) {
                return (string) $legacy->{$col};
            }
        }
        return null;
    }
}
```

PARA: "Fase 1 completa: LegacyHashGuard listo. ¿Procedo a Fase 2 (override Filament Login page)?"

## FASE 2 — Override Filament Login page

Crea `app/Filament/Pages/Auth/Login.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Auth\LegacyHashGuard;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

/**
 * Login page del panel admin.
 *
 * Override la `authenticate()` para delegar en `LegacyHashGuard` con role='admin'.
 * El form schema, layout y middlewares se heredan intactos del BaseLogin.
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();

        try {
            $ok = app(LegacyHashGuard::class)->attempt(
                email: $data['email'],
                password: $data['password'],
                roleHint: 'admin',
                request: request(),
            );
        } catch (ValidationException $e) {
            // Throttle u otra validación. Re-lanza para que Filament muestre el error.
            throw $e;
        }

        if (! $ok) {
            $this->throwFailureValidationException();
        }

        // session.regenerate + remember handled by Auth::login + framework.
        return app(LoginResponse::class);
    }
}
```

PARA: "Fase 2 completa: Login page custom. ¿Procedo a Fase 3 (panel provider)?"

## FASE 3 — Wire Login page en `AdminPanelProvider`

Edita `app/Providers/Filament/AdminPanelProvider.php`. Cambia la línea:

```php
->login()
```

por:

```php
->login(\App\Filament\Pages\Auth\Login::class)
```

Verifica que el resto del provider (cobalto, viteTheme, middlewares) queda intacto.

PARA: "Fase 3 completa: panel usa Login custom. ¿Procedo a Fase 4 (tests del guard)?"

## FASE 4 — Tests del servicio `LegacyHashGuard`

Crea `tests/Feature/Auth/LegacyHashGuardTest.php`:

```php
<?php

declare(strict_types=1);

use App\Auth\LegacyHashGuard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Limpiar el RateLimiter entre tests para evitar bleed.
    RateLimiter::clear('legacy-login:127.0.0.1|info@winfin.es|admin');
    RateLimiter::clear('legacy-login:127.0.0.1|tec@winfin.es|tecnico');
    RateLimiter::clear('legacy-login:127.0.0.1|op@winfin.es|operador');
    RateLimiter::clear('legacy-login:127.0.0.1|x@x.x|admin');
});

function makeRequest(string $ip = '127.0.0.1'): Request
{
    $r = Request::create('/admin/login', 'POST');
    $r->server->set('REMOTE_ADDR', $ip);
    return $r;
}

function guard(): LegacyHashGuard
{
    return app(LegacyHashGuard::class);
}

// Inserta una fila u1 con SHA1('plain') de prueba.
function seedU1(int $userId, string $email, string $plainPassword): void
{
    DB::table('u1')->insert([
        'user_id' => $userId,
        'username' => 'admin'.$userId,
        'email' => $email,
        'password' => sha1($plainPassword),
    ]);
}

function seedTecnico(int $tecnicoId, string $email, string $plainPassword, ?string $nombre = null): void
{
    DB::table('tecnico')->insert([
        'tecnico_id' => $tecnicoId,
        'usuario' => 'usr_tec_'.$tecnicoId,
        'email' => $email,
        'clave' => sha1($plainPassword),
        'nombre_completo' => $nombre ?? 'Tecnico Test '.$tecnicoId,
    ]);
}

function seedOperador(int $operadorId, string $email, string $plainPassword, ?string $razonSocial = null): void
{
    DB::table('operador')->insert([
        'operador_id' => $operadorId,
        'usuario' => 'usr_op_'.$operadorId,
        'email' => $email,
        'clave' => sha1($plainPassword),
        'razon_social' => $razonSocial ?? 'Operador Test '.$operadorId,
    ]);
}

// ---------- Tests obligatorios (DoD Bloque 06 — copilot-instructions.md) ----------

it('legacy_login_rehashes_to_bcrypt', function () {
    seedU1(userId: 100, email: 'admin100@winfin.local', plainPassword: 'secret-pwd');
    expect(User::where('legacy_kind', 'admin')->where('legacy_id', 100)->count())->toBe(0);

    $ok = guard()->attempt('admin100@winfin.local', 'secret-pwd', 'admin', makeRequest());
    expect($ok)->toBeTrue();

    $u = User::where('legacy_kind', 'admin')->where('legacy_id', 100)->firstOrFail();
    expect(str_starts_with($u->password, '$2y$'))->toBeTrue();
    expect($u->legacy_password_sha1)->toBeNull();
    expect($u->lv_password_migrated_at)->not->toBeNull();

    // Segundo login: NO debe re-hacer SQL legacy (cubierto indirectamente: el bcrypt
    // ahora vive en lv_users y el happy path lo prueba).
    Auth::logout();
    expect(guard()->attempt('admin100@winfin.local', 'secret-pwd', 'admin', makeRequest()))->toBeTrue();
});

it('legacy_login_uses_hash_equals', function () {
    // No hay forma directa de testar timing-safety, pero verificamos que el
    // compare se hace contra el sha1() bien lowercased y no rompe con NULL.
    seedU1(userId: 101, email: 'admin101@winfin.local', plainPassword: 'pwd-X');

    expect(guard()->attempt('admin101@winfin.local', 'pwd-X', 'admin', makeRequest()))->toBeTrue();

    // Cambiar el hash a uppercase en BD (algunas tablas legacy lo guardan así).
    DB::table('u1')->where('user_id', 101)->update([
        'password' => strtoupper(sha1('pwd-X')),
    ]);
    User::where('legacy_kind', 'admin')->where('legacy_id', 101)->delete();

    expect(guard()->attempt('admin101@winfin.local', 'pwd-X', 'admin', makeRequest()))
        ->toBeTrue('uppercase SHA1 debe seguir matcheando via strtolower()');
});

it('bcrypt_fail_falls_back_to_legacy_lookup', function () {
    seedU1(userId: 102, email: 'admin102@winfin.local', plainPassword: 'NEW-pwd');
    User::create([
        'legacy_kind' => 'admin',
        'legacy_id' => 102,
        'email' => 'admin102@winfin.local',
        'name' => 'admin102',
        'password' => Hash::make('OLD-pwd'),    // bcrypt obsoleto
        'legacy_password_sha1' => null,
        'lv_password_migrated_at' => now()->subDay(),
    ]);

    // Login con el password NUEVO. Bcrypt falla (OLD-pwd) -> SHA1 acierta -> rehash.
    $ok = guard()->attempt('admin102@winfin.local', 'NEW-pwd', 'admin', makeRequest());
    expect($ok)->toBeTrue();

    $u = User::where('legacy_kind', 'admin')->where('legacy_id', 102)->firstOrFail();
    expect(Hash::check('NEW-pwd', $u->password))->toBeTrue('bcrypt actualizado al password nuevo');
});

it('wrong_password_never_creates_lv_user_row', function () {
    seedU1(userId: 103, email: 'admin103@winfin.local', plainPassword: 'good-pwd');
    expect(User::count())->toBe(0);

    $ok = guard()->attempt('admin103@winfin.local', 'WRONG-pwd', 'admin', makeRequest());
    expect($ok)->toBeFalse();
    expect(User::count())->toBe(0);
});

it('lookup_canonical_by_legacy_kind_legacy_id', function () {
    // Login inicial con email A.
    seedU1(userId: 104, email: 'old@winfin.local', plainPassword: 'pwd');
    expect(guard()->attempt('old@winfin.local', 'pwd', 'admin', makeRequest()))->toBeTrue();

    $rowId = User::where('legacy_kind', 'admin')->where('legacy_id', 104)->value('id');
    expect($rowId)->not->toBeNull();

    // Simular: la app vieja cambia el email del admin.
    DB::table('u1')->where('user_id', 104)->update(['email' => 'new@winfin.local']);

    // Borrar bcrypt para forzar el camino SHA1+updateOrCreate.
    User::where('id', $rowId)->update(['password' => null]);
    Auth::logout();

    // Login con el email NUEVO.
    expect(guard()->attempt('new@winfin.local', 'pwd', 'admin', makeRequest()))->toBeTrue();

    // El lookup canónico actualiza la MISMA fila lv_users (no crea otra nueva).
    expect(User::where('legacy_kind', 'admin')->where('legacy_id', 104)->count())->toBe(1);
    expect(User::find($rowId)->email)->toBe('new@winfin.local');
});

it('login_throttles_after_5_failures', function () {
    seedU1(userId: 105, email: 'x@x.x', plainPassword: 'right');

    for ($i = 0; $i < LegacyHashGuard::MAX_ATTEMPTS; $i++) {
        expect(guard()->attempt('x@x.x', 'wrong', 'admin', makeRequest()))->toBeFalse();
    }

    expect(fn () => guard()->attempt('x@x.x', 'right', 'admin', makeRequest()))
        ->toThrow(ValidationException::class);
});

it('successful_login_clears_rate_limit', function () {
    seedU1(userId: 106, email: 'admin106@winfin.local', plainPassword: 'good');

    // 4 intentos fallidos consecutivos.
    for ($i = 0; $i < 4; $i++) {
        guard()->attempt('admin106@winfin.local', 'wrong', 'admin', makeRequest());
    }

    expect(RateLimiter::attempts('legacy-login:127.0.0.1|admin106@winfin.local|admin'))->toBe(4);

    // Login exitoso.
    expect(guard()->attempt('admin106@winfin.local', 'good', 'admin', makeRequest()))->toBeTrue();

    expect(RateLimiter::attempts('legacy-login:127.0.0.1|admin106@winfin.local|admin'))->toBe(0);
});

// ---------- Tests obligatorios adicionales (ADR-0008) ----------

it('legacy_login_uses_correct_password_column for u1.password', function () {
    seedU1(userId: 200, email: 'a@a.a', plainPassword: 'ppp');
    expect(guard()->attempt('a@a.a', 'ppp', 'admin', makeRequest()))->toBeTrue();
});

it('legacy_login_uses_correct_password_column for tecnico.clave', function () {
    seedTecnico(tecnicoId: 200, email: 't@t.t', plainPassword: 'qqq');
    expect(guard()->attempt('t@t.t', 'qqq', 'tecnico', makeRequest()))->toBeTrue();

    $u = User::where('legacy_kind', 'tecnico')->where('legacy_id', 200)->firstOrFail();
    expect($u->name)->toBe('Tecnico Test 200');
});

it('legacy_login_uses_correct_password_column for operador.clave', function () {
    seedOperador(operadorId: 200, email: 'o@o.o', plainPassword: 'rrr');
    expect(guard()->attempt('o@o.o', 'rrr', 'operador', makeRequest()))->toBeTrue();

    $u = User::where('legacy_kind', 'operador')->where('legacy_id', 200)->firstOrFail();
    expect($u->name)->toBe('Operador Test 200');
});

it('u1_user_id_pk_works_with_lv_users_lookup', function () {
    // Verifica que el guard usa user_id (no u1_id) como legacy_id.
    seedU1(userId: 999, email: 'admin999@winfin.local', plainPassword: 'pwd');
    expect(guard()->attempt('admin999@winfin.local', 'pwd', 'admin', makeRequest()))->toBeTrue();

    expect(User::where('legacy_kind', 'admin')->where('legacy_id', 999)->exists())->toBeTrue();
});

it('email_change_in_legacy_after_first_login_does_not_create_new_lv_user_row', function () {
    seedU1(userId: 300, email: 'orig@a.a', plainPassword: 'pp');
    guard()->attempt('orig@a.a', 'pp', 'admin', makeRequest());
    $idBefore = User::where('legacy_kind', 'admin')->where('legacy_id', 300)->value('id');

    DB::table('u1')->where('user_id', 300)->update(['email' => 'changed@a.a']);
    User::where('id', $idBefore)->update(['password' => null]); // forzar SHA1 path
    Auth::logout();

    guard()->attempt('changed@a.a', 'pp', 'admin', makeRequest());

    expect(User::where('legacy_kind', 'admin')->where('legacy_id', 300)->count())->toBe(1);
    expect(User::find($idBefore)->email)->toBe('changed@a.a');
});
```

NOTA — este test file usa las tablas legacy del schema `legacy_test` cargado por `AppServiceProvider::boot()` en environment testing (Bloque 03). Verifica que existen las columnas necesarias:
- `u1`: user_id, username, email, password
- `tecnico`: tecnico_id, usuario, email, clave, nombre_completo
- `operador`: operador_id, usuario, email, clave, nombre

Si alguna no está en `database/migrations/legacy_test/2026_04_30_000000_create_legacy_tables.php`, AVISA antes de seguir y propón añadirla (no la añadas tú directamente — necesita validación).

Corre tests:

```bash
./vendor/bin/pest tests/Feature/Auth/LegacyHashGuardTest.php --colors=never --compact 2>&1 | tail -30
```

Si todos verde, sigue. Si fallan, AVISA con el error concreto antes de tocar el guard — primero entendemos qué falla.

PARA: "Fase 4 completa: 11 tests del guard verdes. ¿Procedo a Fase 5 (test integración Filament)?"

## FASE 5 — Test integración Filament

Crea `tests/Feature/Auth/AdminLoginTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('legacy-login:127.0.0.1|info@winfin.es|admin');
});

it('admin_login_via_filament_uses_legacy_hash_guard', function () {
    DB::table('u1')->insert([
        'user_id' => 1,
        'username' => 'admin',
        'email' => 'info@winfin.es',
        'password' => sha1('test-pwd'),
    ]);

    Livewire::test(Login::class)
        ->set('data.email', 'info@winfin.es')
        ->set('data.password', 'test-pwd')
        ->call('authenticate');

    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->legacy_kind)->toBe('admin');
    expect(auth()->user()->legacy_id)->toBe(1);
});

it('admin_login_rejects_wrong_password', function () {
    DB::table('u1')->insert([
        'user_id' => 2,
        'username' => 'admin2',
        'email' => 'admin2@winfin.local',
        'password' => sha1('right'),
    ]);

    Livewire::test(Login::class)
        ->set('data.email', 'admin2@winfin.local')
        ->set('data.password', 'wrong')
        ->call('authenticate')
        ->assertHasErrors();

    expect(auth()->check())->toBeFalse();
    expect(User::count())->toBe(0);
});
```

```bash
./vendor/bin/pest tests/Feature/Auth/AdminLoginTest.php --colors=never --compact 2>&1 | tail -15
```

PARA: "Fase 5 completa: integración Filament verde. ¿Procedo a Fase 6 (smoke total)?"

## FASE 6 — Smoke total

```bash
./vendor/bin/pint --test 2>&1 | tail -5
./vendor/bin/pest --colors=never --compact 2>&1 | tail -10
npm run build 2>&1 | tail -3
```

Verifica:
- Pint clean. Si reporta cambios, corre `./vendor/bin/pint` y commitea como `style:` aparte.
- Pest todos los tests verdes (los previos de Bloque 01-05 más los nuevos de 06).
- npm build sin errores.

PARA: "Fase 6 completa. ¿Procedo a Fase 7 (commits + PR)?"

## FASE 7 — Commits + push + PR

Stage explícito por archivo. Estructura sugerida:

1. `docs: add Bloque 06 prompt (LegacyHashGuard)` — solo `docs/prompts/06-auth-sha1-bcrypt.md`.
2. `feat(auth): add LegacyHashGuard service with rate limit + canonical lookup` — `app/Auth/LegacyHashGuard.php`.
3. `feat(filament): override admin Login page to delegate in LegacyHashGuard` — `app/Filament/Pages/Auth/Login.php` + `app/Providers/Filament/AdminPanelProvider.php`.
4. `test: cover LegacyHashGuard for the 11 obligatory cases` — `tests/Feature/Auth/LegacyHashGuardTest.php` + `tests/Feature/Auth/AdminLoginTest.php`.

Smoke final:

```bash
./vendor/bin/pest --colors=never --compact 2>&1 | tail -5
git push -u origin bloque-06-legacy-hash-guard
```

Crea PR:

```bash
gh pr create \
  --base main \
  --head bloque-06-legacy-hash-guard \
  --title "Bloque 06 — LegacyHashGuard: lazy SHA1->bcrypt + rate limit + canonical lookup" \
  --body "$(cat <<'BODY'
## Resumen

Implementa el servicio `App\Auth\LegacyHashGuard` que valida login contra hashes legacy SHA1 (u1.password / tecnico.clave / operador.clave) y rehashea lazy a bcrypt en `lv_users` por (legacy_kind, legacy_id). Conecta el panel admin de Filament para usar el guard.

Cierra ADR-0003 (auth migration) y ADR-0008 (auth field correction). Bloque crítico de seguridad.

## Qué entra

- `app/Auth/LegacyHashGuard.php` — servicio puro reusable desde Filament (admin) y futuras PWAs (técnico/operador, Bloques 11/12). 5 fases: rate limit -> resolver legacy -> lookup canónico (legacy_kind, legacy_id) -> bcrypt OK / SHA1 fallback -> updateOrCreate + login.
- `app/Filament/Pages/Auth/Login.php` — override del Login default de Filament para delegar en el guard con role='admin'.
- `app/Providers/Filament/AdminPanelProvider.php` — usa la Login custom.
- 11 tests obligatorios (DoD del proyecto):
  - legacy_login_rehashes_to_bcrypt
  - legacy_login_uses_hash_equals
  - bcrypt_fail_falls_back_to_legacy_lookup
  - wrong_password_never_creates_lv_user_row
  - lookup_canonical_by_legacy_kind_legacy_id
  - login_throttles_after_5_failures
  - successful_login_clears_rate_limit
  - legacy_login_uses_correct_password_column (parametrizado para u1/tecnico/operador)
  - u1_user_id_pk_works_with_lv_users_lookup
  - email_change_in_legacy_after_first_login_does_not_create_new_lv_user_row
  - admin_login_via_filament_uses_legacy_hash_guard

## Decisiones clave

- **Rate limit 5/60s** por (IP, email lowercased, role). Aplicado ANTES de cualquier query a BD para que el ataque sea barato de bloquear (relevante por el dump SQL público que filtró los 107 SHA1 — incidente RGPD aún pendiente).
- **`hash_equals(sha1($plain), strtolower($legacyHash))`** — timing-safe + tolerante a hashes legacy en mayúsculas.
- **Lookup canónico por (legacy_kind, legacy_id)** tras resolver la fila legacy. NUNCA por email. Si el email cambia en la app vieja entre logins, la fila lv_users sigue siendo la misma (test cubre el caso).
- **`updateOrCreate` por (legacy_kind, legacy_id)** — idempotente, sin colisiones.
- **`legacy_password_sha1` se borra tras primer login OK** (nunca persiste en lv_users post-rehash).
- **Field mapping por rol** según ADR-0008: u1.password / tecnico.clave / operador.clave; PKs user_id / tecnico_id / operador_id.

## Qué NO entra

- Login para técnicos (`/tecnico/login`) y operadores (`/operador/login`) — viven en sus PWAs (Bloques 11/12). Pero el guard YA está probado para los 3 roles via tests directos. Cuando lleguen las PWAs reusan el mismo servicio.
- Reset de password vía email — no aplica al esquema legacy.
- Logout custom — el default de Filament basta.

## CI esperado

3/3 jobs verde (PHP 8.2, PHP 8.3, Vite build).
BODY
)"
```

Espera CI verde:

```bash
sleep 8
PR_NUM=$(gh pr list --head bloque-06-legacy-hash-guard --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

Cuando todo verde:

```
✅ Qué he hecho:
   - LegacyHashGuard servicio puro con 5 fases (rate limit, legacy lookup, lv_users canonical, bcrypt/SHA1 chain, updateOrCreate).
   - Filament admin Login override delega en el guard con role='admin'.
   - 11 tests obligatorios + 2 integración Livewire.
   - Pint + pest + build verdes.
   - 4 commits Conventional Commits.
   - PR #N: [URL].
   - CI 3/3 verde.

⏳ Qué falta:
   - (Manual, post-merge) Verificar login real en http://127.0.0.1:8000/admin/login con info@winfin.es + password de Bloque 05.
   - Bloque 07 — Filament resource para Piv (incluye eager loading + validación municipio ADR-0007).

❓ Qué necesito del usuario:
   - Confirmar URL del PR.
   - Mergear cuando esté revisado (sugiero Rebase and merge).
   - Tras merge, probar login en navegador (debe funcionar idéntico al Bloque 05 — el guard mantiene compatibilidad con el admin existente).
```

NO mergees el PR.

END PROMPT
```

---

## Después de Bloque 06

1. Tras merge, smoke manual: `php artisan serve` + navegador a `http://127.0.0.1:8000/admin/login` con `info@winfin.es` + password generado en Bloque 05. **Debe funcionar igual** — el guard hace bcrypt match en `lv_users` (camino feliz post-migración) sin tocar SHA1 legacy. Cualquier divergencia es bug.

2. Pasar a **Bloque 07** — Filament resource para `Piv` con eager loading obligatorio (`expectQueryCount`), validación `municipio` según ADR-0007, tabla con búsqueda + filtros.

3. **TODO opcional**: cuando se borre el dump SQL público en Site Tools (incidente RGPD pendiente), retirar la urgencia del rate limiter en código documentación. El rate limiter sigue siendo necesario, pero la justificación cambia (defensa en profundidad vs ataque inminente).
