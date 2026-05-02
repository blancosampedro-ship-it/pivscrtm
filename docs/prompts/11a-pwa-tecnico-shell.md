# Bloque 11a — PWA Técnico: shell + auth + dashboard (sin cierre flow)

## Contexto

Tras Bloque 10 (PR #24, mergeado en `37f27da`), la app tiene:

- 23 PRs mergeados, 156 tests verde.
- Admin Filament completo: dashboard KPIs, Reportes cross-panel, CSV exports RGPD-safe, Carbon visual.
- `LegacyHashGuard` (Bloque 06) ya soporta `roleHint='tecnico'` (verificado en [LegacyHashGuard.php:39-44](app/Auth/LegacyHashGuard.php)).
- `User::isTecnico()` y `User::legacyEntity()` existentes (Bloque 06/ADR-0008).
- Tokens Carbon en `tailwind.config.js` raíz (Bloque 09d).

**Bloque 11a entrega la PRIMERA mitad de la PWA técnico**: login, layout shell, dashboard con lista de "mis asignaciones abiertas". Sin cierre flow (eso es Bloque 11b). El técnico puede entrar, ver qué tiene asignado, y salir. Suficiente para validar la base auth+IA antes de construir el flujo crítico encima.

**Lo que NO entra en este bloque (queda para 11b):**
- Service worker + PWA installable completa (vite-plugin-pwa).
- Detail page de asignación.
- Cierre flow + foto upload.
- `AsignacionCierreService` extracted.

## Restricciones inviolables que aplican

- **DESIGN.md §10.2 — touch targets ≥ 88px** en CTAs primarios de la PWA técnico (guantes / sol). Utilidad `tap-target` debe materializarse y aplicarse al botón "Entrar" del login y al botón logout.
- **DESIGN.md §11.1 (regla #11) — separación visual avería/revisión**: aunque en 11a NO hay form de cierre, la lista del dashboard SÍ muestra asignaciones de ambos tipos. Cada card debe llevar **stripe lateral 4px**: Red 60 (`--support-error`) si `tipo=1` correctivo, Green 50 (`--support-success`) si `tipo=2` revisión. Subtítulo desambiguador obligatorio: "Avería real" vs "Revisión mensual".
- **Wordmark "Winfin *PIV*"** con Instrument Serif italic en la "f" en el header del shell — misma decisión deliberada que en Filament admin (DESIGN.md §3 excepción única).
- **Single web guard** — no añadir un guard separado `tecnico`. Reusar el guard `web` default. La distinción de rol se hace por `User::isTecnico()` en middleware nuevo.
- **NO romper tests existentes**. Los 156 tests deben seguir verdes.
- **Carbon tokens existentes**. NO inventar colores nuevos. Usar lo que ya está en `tailwind.config.js`: `bg-layer-0`, `bg-layer-1`, `text-ink-primary`, `text-ink-secondary`, `border-error`, `border-success`, `bg-primary-60`, `tap-target`, etc. Si una utilidad no existe (`tap-target` no está aún), añadirla en `app.css` `@layer components`.

## Plan de cambios

### 1. Composer dependency

Añadir Volt como dependencia directa:

```bash
composer require livewire/volt
php artisan volt:install
```

`volt:install` registra `VoltServiceProvider` y crea `resources/views/livewire/` si no existe. Verificar en `bootstrap/providers.php` (Laravel 12) que aparezca.

### 2. `app/Http/Middleware/EnsureTecnico.php` — nuevo

Middleware que valida auth + role en una sola pasada. Si el usuario no está autenticado o no es técnico, redirige a `/tecnico/login`. Si está autenticado pero su `Tecnico::status !== 1`, también redirige (con flash).

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tecnico;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTecnico
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isTecnico()) {
            return redirect()->route('tecnico.login');
        }

        // Verificar que el técnico está activo (status=1) en la tabla legacy.
        // Si fue desactivado entre logins, sesión queda invalidada.
        $tecnico = $user->legacyEntity();
        if (! $tecnico instanceof Tecnico || (int) $tecnico->status !== 1) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('tecnico.login')
                ->withErrors(['email' => 'Cuenta de técnico inactiva.']);
        }

        return $next($request);
    }
}
```

Registrar alias en `bootstrap/app.php` dentro de `withMiddleware`:

```php
$middleware->alias([
    'tecnico' => \App\Http\Middleware\EnsureTecnico::class,
]);
```

### 3. `routes/web.php` — añadir grupo `/tecnico/*`

Mantener todo lo que ya hay. Añadir:

```php
use Livewire\Volt\Volt;

Volt::route('/tecnico/login', 'tecnico.login')->name('tecnico.login');

Route::middleware('tecnico')->prefix('tecnico')->name('tecnico.')->group(function () {
    Volt::route('/', 'tecnico.dashboard')->name('dashboard');
    Route::post('/logout', \App\Http\Controllers\Tecnico\LogoutController::class)->name('logout');
});
```

### 4. `app/Http/Controllers/Tecnico/LogoutController.php` — nuevo

Controller invocable mínimo:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tecnico;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class LogoutController
{
    public function __invoke(Request $request): RedirectResponse
    {
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tecnico.login');
    }
}
```

### 5. `resources/views/components/tecnico/shell.blade.php` — nuevo

Layout shell mobile-first. Top bar `bg-ink-primary` + main slot. Wordmark con Instrument Serif italic.

```blade
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0F62FE">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <title>{{ $title ?? 'Winfin PIV — Técnico' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-layer-0 text-ink-primary min-h-screen font-sans antialiased">
    <header class="bg-ink-primary text-ink-on_color flex items-center justify-between px-4 h-14 sticky top-0 z-10">
        <a href="{{ route('tecnico.dashboard') }}" class="brand text-base font-semibold tracking-tight">
            Win<em>f</em>in <strong>PIV</strong>
        </a>
        @auth
        <div class="flex items-center gap-3">
            <span class="text-sm text-ink-on_color/80">{{ auth()->user()->name }}</span>
            <form action="{{ route('tecnico.logout') }}" method="POST">
                @csrf
                <button type="submit"
                        class="tap-target-icon flex items-center justify-center"
                        aria-label="Cerrar sesión">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/>
                    </svg>
                </button>
            </form>
        </div>
        @endauth
    </header>
    <main class="pb-safe">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
```

### 6. `resources/views/livewire/tecnico/login.blade.php` — nuevo (Volt)

Login con email + password. Llama a `LegacyHashGuard::attempt(..., 'tecnico', ...)`. Si OK, valida que `status=1` (defensa adicional aunque `EnsureTecnico` también lo cubra). Redirige a `tecnico.dashboard`.

```blade
<?php

use App\Auth\LegacyHashGuard;
use App\Models\Tecnico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $email = '';
    public string $password = '';

    public function login(LegacyHashGuard $guard, Request $request): void
    {
        $this->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $ok = $guard->attempt($this->email, $this->password, 'tecnico', $request);

        if (! $ok) {
            throw ValidationException::withMessages([
                'email' => 'Credenciales no válidas.',
            ]);
        }

        $user = Auth::user();
        $tecnico = $user?->legacyEntity();
        if (! $tecnico instanceof Tecnico || (int) $tecnico->status !== 1) {
            Auth::logout();
            $request->session()->invalidate();
            throw ValidationException::withMessages([
                'email' => 'Cuenta de técnico inactiva. Contacta con admin.',
            ]);
        }

        $request->session()->regenerate();
        $this->redirect(route('tecnico.dashboard'), navigate: false);
    }
}; ?>

<x-tecnico.shell>
    <div class="min-h-[calc(100vh-3.5rem)] flex flex-col items-center justify-center p-6">
        <div class="w-full max-w-sm">
            <h1 class="text-xl font-medium mb-6 text-center">Acceso técnico</h1>

            <form wire:submit="login" class="space-y-4">
                <div>
                    <label for="email" class="block text-xs font-normal text-ink-secondary mb-1 tracking-wider">Email</label>
                    <input type="email"
                           id="email"
                           wire:model="email"
                           autocomplete="username"
                           required
                           class="w-full bg-bg-field text-ink-primary border-0 border-b-2 border-transparent focus:border-primary-60 focus:outline-none px-3 py-3 text-base">
                    @error('email') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password" class="block text-xs font-normal text-ink-secondary mb-1 tracking-wider">Contraseña</label>
                    <input type="password"
                           id="password"
                           wire:model="password"
                           autocomplete="current-password"
                           required
                           class="w-full bg-bg-field text-ink-primary border-0 border-b-2 border-transparent focus:border-primary-60 focus:outline-none px-3 py-3 text-base">
                    @error('password') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                </div>

                <button type="submit"
                        class="tap-target w-full bg-primary-60 hover:bg-primary-70 text-ink-on_color font-medium text-base">
                    <span wire:loading.remove wire:target="login">Entrar</span>
                    <span wire:loading wire:target="login">Verificando…</span>
                </button>
            </form>
        </div>
    </div>
</x-tecnico.shell>
```

Nota sobre `bg-bg-field`: `bg-field` no existe como token directo en `tailwind.config.js` raíz — use `bg-layer-1` que es Gray 10 (`#F4F4F4`), funcionalmente equivalente al input field bg de Carbon. Si hay duda, verificar el archivo y usar lo que ya está exportado.

### 7. `resources/views/livewire/tecnico/dashboard.blade.php` — nuevo (Volt)

Dashboard con lista de asignaciones abiertas del técnico autenticado. Cards con stripe regla #11.

```blade
<?php

use App\Models\Asignacion;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        $tecnicoId = (int) auth()->user()->legacy_id;

        return [
            'asignacionesAbiertas' => Asignacion::query()
                ->where('tecnico_id', $tecnicoId)
                ->where('status', 1)
                ->with(['averia.piv'])
                ->orderByDesc('fecha')
                ->get(),
        ];
    }
}; ?>

<x-tecnico.shell>
    <div class="p-4">
        <h1 class="text-lg font-medium mb-4">Mis asignaciones abiertas</h1>

        @if ($asignacionesAbiertas->isEmpty())
            <div class="bg-layer-1 p-8 text-center text-ink-secondary text-sm">
                No tienes asignaciones abiertas ahora mismo.
            </div>
        @else
            <ul class="space-y-3" role="list">
                @foreach ($asignacionesAbiertas as $asignacion)
                    @php
                        $isCorrectivo = (int) $asignacion->tipo === 1;
                        $stripeColor  = $isCorrectivo ? 'border-error' : 'border-success';
                        $kicker       = $isCorrectivo ? 'Avería real' : 'Revisión mensual';
                        $subtitle     = $isCorrectivo
                            ? 'Hay un fallo. Crear parte correctivo.'
                            : 'Todo OK. Checklist mensual rutinario.';
                        $piv          = $asignacion->averia?->piv;
                    @endphp
                    <li class="bg-layer-0 border-l-4 {{ $stripeColor }} p-4 flex flex-col gap-2 shadow-none">
                        <div class="text-xs uppercase tracking-wider text-ink-secondary font-medium">
                            {{ $kicker }}
                        </div>
                        <div class="text-base font-medium leading-tight">
                            @if ($piv)
                                Panel #{{ str_pad((string) $piv->piv_id, 3, '0', STR_PAD_LEFT) }}
                                <span class="font-mono text-sm text-ink-secondary ml-1">· {{ $piv->parada_cod }}</span>
                            @else
                                <span class="text-ink-secondary">Panel sin asignar</span>
                            @endif
                        </div>
                        @if ($piv)
                            <div class="text-sm text-ink-secondary leading-snug">
                                {{ $piv->direccion }}
                            </div>
                        @endif
                        <div class="text-xs text-ink-secondary mt-1">
                            {{ $subtitle }}
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-tecnico.shell>
```

### 8. `resources/css/app.css` — añadir `tap-target` utilities

Al final del bloque `@layer base` o en un nuevo `@layer components`, añadir:

```css
@layer components {
    /* Touch targets ≥ 88px para PWA técnico (DESIGN.md §10.2 — guantes/sol). */
    .tap-target {
        min-height: 88px;
        padding: 0 1.5rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .tap-target-icon {
        min-height: 56px;
        min-width: 56px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    /* Safe area insets para iOS notch/home bar */
    .pb-safe {
        padding-bottom: env(safe-area-inset-bottom);
    }
}
```

### 9. `public/manifest.webmanifest` — nuevo

PWA manifest mínimo. Sin SW en 11a — solo "installable" via add-to-homescreen. Iconos placeholder por ahora (Bloque 11b traerá el set definitivo).

```json
{
  "name": "Winfin PIV — Técnico",
  "short_name": "Winfin PIV",
  "description": "Gestión de paneles de información al viajero — vista técnico de campo.",
  "start_url": "/tecnico",
  "scope": "/tecnico/",
  "display": "standalone",
  "orientation": "portrait",
  "theme_color": "#0F62FE",
  "background_color": "#FFFFFF",
  "lang": "es",
  "icons": [
    { "src": "/favicon.ico", "sizes": "32x32", "type": "image/x-icon" }
  ]
}
```

Comentario explícito en el reporte final: iconos definitivos van en Bloque 11b (no es regresión, es scope).

### 10. Tests DoD — `tests/Feature/Bloque11aTecnicoShellTest.php`

Cinco tests obligatorios. Crear con `RefreshDatabase`. Importar `App\Models\Tecnico` y `App\Models\User`.

```php
<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('tecnico_login_screen_renders_at_tecnico_login', function () {
    $response = $this->get(route('tecnico.login'));
    $response->assertOk();
    $response->assertSeeText('Acceso técnico');
});

it('tecnico_can_login_with_legacy_sha1_password', function () {
    // Tecnico legacy con SHA1 password; LegacyHashGuard debe migrarlo a bcrypt al entrar.
    $tecnico = Tecnico::factory()->create([
        'tecnico_id' => 99001,
        'email'      => 'test.tecnico@winfin.local',
        'clave'      => sha1('SECRET-pass-123'),
        'status'     => 1,
        'nombre_completo' => 'Test Técnico',
    ]);

    $response = Livewire\Volt\Volt::test('tecnico.login')
        ->set('email', 'test.tecnico@winfin.local')
        ->set('password', 'SECRET-pass-123')
        ->call('login');

    $response->assertHasNoErrors();
    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->isTecnico())->toBeTrue();
    expect((int) auth()->user()->legacy_id)->toBe(99001);
});

it('tecnico_dashboard_requires_authentication', function () {
    $response = $this->get(route('tecnico.dashboard'));
    $response->assertRedirect(route('tecnico.login'));
});

it('admin_user_cannot_access_tecnico_dashboard', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('tecnico.dashboard'));
    $response->assertRedirect(route('tecnico.login'));
});

it('tecnico_dashboard_shows_only_my_open_asignaciones', function () {
    // Mi técnico
    $miTecnico = Tecnico::factory()->create(['tecnico_id' => 99010, 'status' => 1]);
    $miUser = User::factory()->create([
        'legacy_kind' => 'tecnico',
        'legacy_id'   => 99010,
        'email'       => 'mi.tecnico@winfin.local',
    ]);

    // Otro técnico (sus asignaciones NO deben aparecer en mi dashboard)
    $otroTecnico = Tecnico::factory()->create(['tecnico_id' => 99011, 'status' => 1]);

    $piv = Piv::factory()->create(['piv_id' => 99500]);

    // 2 asignaciones abiertas mías + 1 cerrada mía + 1 abierta de otro
    foreach ([99100, 99101] as $i) {
        $av = Averia::factory()->create(['averia_id' => $i, 'piv_id' => 99500]);
        Asignacion::factory()->create([
            'asignacion_id' => $i, 'averia_id' => $i, 'tecnico_id' => 99010, 'status' => 1, 'tipo' => 1,
        ]);
    }
    $av = Averia::factory()->create(['averia_id' => 99102, 'piv_id' => 99500]);
    Asignacion::factory()->create([
        'asignacion_id' => 99102, 'averia_id' => 99102, 'tecnico_id' => 99010, 'status' => 2, 'tipo' => 1,
    ]);
    $av = Averia::factory()->create(['averia_id' => 99103, 'piv_id' => 99500]);
    Asignacion::factory()->create([
        'asignacion_id' => 99103, 'averia_id' => 99103, 'tecnico_id' => 99011, 'status' => 1, 'tipo' => 1,
    ]);

    $response = $this->actingAs($miUser)->get(route('tecnico.dashboard'));

    $response->assertOk();
    $response->assertSeeText('Mis asignaciones abiertas');
    // 2 abiertas mías visibles
    $response->assertSeeText('Panel #' . str_pad('99500', 3, '0', STR_PAD_LEFT));
    // (Aserción más fuerte: contar cards. Si Copilot puede usar assertSeeInOrder o conteos, mejor.)
});

it('tecnico_logout_returns_to_login', function () {
    $tecnico = Tecnico::factory()->create(['tecnico_id' => 99020, 'status' => 1]);
    $user = User::factory()->create([
        'legacy_kind' => 'tecnico', 'legacy_id' => 99020, 'email' => 'logout@test.local',
    ]);

    $response = $this->actingAs($user)
        ->post(route('tecnico.logout'));

    $response->assertRedirect(route('tecnico.login'));
    expect(auth()->check())->toBeFalse();
});
```

**Importante:** estos tests son funcionales reales (HTTP + Volt::test). Si Copilot detecta que `Volt::test('tecnico.login')` no funciona o el componente no se monta, NO degradar a un grep test del .blade.php. Es banderazo rojo. Pivot legítimo: usar `Livewire::test('tecnico::login')` o el namespace que volt:install registre.

## Verificación obligatoria antes del commit final

1. **Composer:** `composer require livewire/volt` instala v1.7+. `php artisan volt:install` ejecuta limpio.
2. **Tests:** `vendor/bin/pest` → 156 previos + 6 nuevos = **162 verde**.
3. **Pint:** `vendor/bin/pint --test` OK sobre los nuevos PHP.
4. **Build:** `npm run build` OK.
5. **Smoke HTTP:**
   - `curl -sI http://127.0.0.1:8000/tecnico/login` → 200.
   - `curl -sI http://127.0.0.1:8000/tecnico` (anon) → 302 a /tecnico/login.
   - `curl -sI http://127.0.0.1:8000/manifest.webmanifest` → 200, `Content-Type: application/manifest+json` o `application/json`.
6. **CI:** push → 3/3 verde.

## Smoke real obligatorio (post-merge, a cargo del usuario)

CRÍTICO: el smoke debe hacerse en **viewport mobile** — Safari iOS o Chrome DevTools mobile mode (iPhone 14 / Galaxy S20). Desktop oculta los problemas de touch targets y layout estrecho.

1. **Safari → DevTools (Develop menu) → User Agent → iOS, o directamente Responsive Design Mode (`Cmd+Opt+R` en Safari).**
2. **`http://127.0.0.1:8000/tecnico/login`:**
   - Layout centra el form en pantalla, max-w-sm, padding 24px.
   - Wordmark "Winfin *PIV*" en header con la "f" en Instrument Serif italic.
   - Inputs bottom-border-only (Carbon).
   - Botón "Entrar" altura ≥88px (mide con DevTools).
   - Login con credenciales válidas técnico (`info@winfin.es` NO sirve — es admin).
3. **Si tienes credenciales reales de un técnico activo (status=1), prueba el login.** Si no, crea uno desde un seeder o por psql/MySQL CLI con un SHA1 conocido. (Documentar en el reporte qué pasos hiciste.)
4. **`/tecnico` post-login:**
   - Header con wordmark + nombre técnico + icono logout.
   - Lista de asignaciones abiertas — cada card con stripe lateral 4px:
     - Rojo `#DA1E28` para correctivos.
     - Verde `#24A148` para revisiones.
   - Subtítulo desambiguador en cada card.
   - "Avería real" / "Revisión mensual" en small-caps tracking-wider arriba del título.
5. **Logout:** click en icono → vuelve a /tecnico/login. Sesión limpia.
6. **Intento de acceso cruzado:** entrar como admin (`info@winfin.es`) en `/admin/login`, luego ir a `/tecnico` → debe redirigir a `/tecnico/login` (no permitir admin en zona técnico).

## Definition of Done

- 1 PR (#25) con 2-3 commits coherentes:
  - `chore(deps): require livewire/volt`
  - `feat(tecnico): add PWA shell, login, and dashboard`
  - (opcional) `test: cover tecnico shell auth and dashboard`
- CI 3/3 verde.
- ~162 tests verde (156 + 6 nuevos).
- Working tree clean tras push.
- PR review-ready (no draft).

## Reporte final que Copilot debe entregar

- SHAs de los commits.
- Diff resumen.
- Estado CI tras push.
- Confirmación de los 6 endpoints/checks del smoke local HTTP.
- Lista visual pendiente para el usuario (los 6 puntos del smoke real arriba — viewport mobile obligatorio).
- Pivot explícito si:
  - `Volt::test()` no se pudo usar y se cambió a `Livewire::test()` con namespace.
  - Algún token Carbon (`bg-bg-field`, etc.) no existe y se sustituyó por equivalente.
  - El icono de logout SVG inline causa fricción y se sustituyó por una alternativa.
- Confirmar explícitamente: **AsignacionCierreService extracción NO se hizo en este bloque** — queda para 11b.
