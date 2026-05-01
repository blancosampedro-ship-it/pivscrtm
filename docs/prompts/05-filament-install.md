# Bloque 05 — Filament 3.2 install + custom theme + FilamentUser gate

> **Cómo se usa:** copia el bloque `BEGIN PROMPT` … `END PROMPT` y pégalo en VS Code Copilot Chat (modo Agent). ~45-60 min.

---

## Objetivo

Instalar Filament 3.2 + crear el panel admin (`/admin`) con tema visual custom que aplica los tokens de [DESIGN.md](../../DESIGN.md) (cobalto `#1D3F8C`, Instrument Serif + General Sans, fondo `#FAFAF7`). Hacer que `App\Models\User` implemente `FilamentUser` con `canAccessPanel()` que solo deja entrar a admins (`legacy_kind='admin'`).

**Fuera de alcance** (queda para post-merge, lo hace Claude/usuario vía SSH):
- `php artisan migrate` contra MySQL producción para crear las tablas `lv_*`.
- Crear el primer admin en `lv_users` vía tinker.
- Verificar login real en `https://piv.winfin.es/admin/login`.

Bloque 05 entrega solo el **código**. La activación contra prod es runbook separado al final de este archivo.

**Fuera de alcance también** (Bloque 06):
- Login para técnicos / operadores.
- `LegacyHashGuard` con lookup canónico + rate limiting + lazy creation.
- Rutas `/tecnico/login` y `/operador/login`.

Bloque 05 usa el **Authenticatable default de Laravel** (bcrypt contra `lv_users.password`). Funciona porque el primer admin se crea con bcrypt-only (legacy_password_sha1=NULL, lv_password_migrated_at=now()). El guard custom de Bloque 06 lo cubrirá luego para los otros roles.

---

## Definition of Done

1. Filament 3.2 instalado vía composer (PHP 8.2 floor respetado en composer.lock).
2. `app/Providers/Filament/AdminPanelProvider.php` con `id('admin')`, `path('admin')`, `default()`, `viteTheme()`, `colors()` apuntando al cobalto.
3. Provider registrado en `bootstrap/providers.php`.
4. Theme CSS en `resources/css/filament/admin/theme.css` que importa Bunny Fonts + tokens DESIGN.md.
5. Tailwind config Filament en `resources/css/filament/admin/tailwind.config.js` extendiendo los presets de Filament + tokens.
6. Vite input actualizado para compilar el theme.
7. `App\Models\User` implementa `Filament\Models\Contracts\FilamentUser` con `canAccessPanel()` returning `$this->isAdmin()`.
8. Tests Pest:
   - Panel `admin` está registrado.
   - Theme está apuntado al archivo correcto en el provider.
   - `canAccessPanel()` returns true para admin, false para tecnico y operador.
   - Login route `/admin/login` responde 200 en GET (sanity check; sin tener admin user creado, basta con que la vista renderice).
9. `pint --test`, `pest`, `npm run build` verdes.
10. PR creado, CI 3/3 verde.
11. Runbook post-merge (`docs/runbooks/05-prod-deploy-filament.md`) con los pasos exactos para Claude/usuario al activar Filament en prod.

---

## Riesgos y mitigaciones

- **Filament 3.2 vs Tailwind 3.4.19**: confirmado compatible (Filament 3 oficial soporta TW 3 y NO soporta TW 4 todavía). El downgrade del Bloque 01 fue exactamente para esto.
- **Filament puede pinar PHP 8.3+ en composer.json transitivamente**. Mitigación: nuestro `composer.json` ya tiene `config.platform.php = "8.2.30"` (Bloque 01b). Cualquier dep que requiera 8.3+ fallará al `composer require` y avisaremos.
- **`make:filament-panel` vs `filament:install --panels`**: el primero es no-interactivo y crea directamente `AdminPanelProvider`. Lo usamos.
- **Vite input array**: hay que mantener `resources/css/app.css` y `resources/js/app.js` que ya están + añadir `resources/css/filament/admin/theme.css`. NO sobrescribir.
- **El theme CSS de Filament hereda los presets oficiales de Filament**: usa `@import "../../../../vendor/filament/filament/resources/css/theme.css";` arriba del archivo. Sin ese import, el panel sale roto.
- **`User` ahora implementa `FilamentUser`**: tests del Bloque 04 (`UserTest`) deben seguir verde. La interface no añade campos, solo un método (`canAccessPanel`).
- **Admin actual sin row en `lv_users`**: hasta el runbook post-merge no hay admin real. Localmente, el `pest` usa SQLite memory + factories — no necesita admin real. Solo el navegador pegado a SiteGround necesitaría el admin (queda para post-merge).
- **`canAccessPanel` recibe `Panel $panel`**: si en el futuro hay panel `tecnico` o `operador`, pueden discriminarse por `$panel->getId()`. Por ahora solo hay `admin` así que basta con `isAdmin()`.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md (convenciones)
- CLAUDE.md (división trabajo)
- DESIGN.md (sistema visual — sección 4 Color, 9 Aplicación al stack/Filament)
- docs/decisions/0005-user-unification.md (legacy_kind + canAccessPanel rationale)
- docs/decisions/0008-auth-field-correction.md (campos auth por tabla)
- docs/prompts/05-filament-install.md (este archivo, secciones objetivo y riesgos)

Tu tarea: implementar el Bloque 05. Instalar Filament 3.2 + tema custom DESIGN.md + restricción canAccessPanel a admins.

Trabajas SOLO en código local (SQLite memory para tests). El deploy a prod (migrate + crear admin) NO es parte de este bloque.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch

```bash
pwd                              # /Users/winfin/Documents/winfin-piv
git branch --show-current        # main
git rev-parse HEAD               # debe ser 557c890 (post Bloque 04)
git status --short               # vacío
php --version                    # 8.2.x o 8.4.x (Mac); composer respeta floor 8.2.30
./vendor/bin/pest --version      # Pest 3.x
node --version                   # v22.x
```

Si algo no encaja, AVISA y para.

```bash
git checkout -b bloque-05-filament-install
```

PARA: "Branch creada. ¿Procedo a Fase 1 (composer require filament)?"

## FASE 1 — Instalar Filament 3.2

```bash
composer require filament/filament:"^3.2" --no-interaction
```

Espera resolución. Si falla por conflicto de versión PHP, AVISA y muestra el error.

Tras instalar:

```bash
composer show filament/filament | grep -E "^name|^versions|^require"
php artisan --version
```

PARA: "Filament instalado: vX.Y.Z. ¿Procedo a Fase 2 (crear panel admin)?"

## FASE 2 — Crear panel admin

```bash
php artisan make:filament-panel admin
```

Esto genera `app/Providers/Filament/AdminPanelProvider.php` y lo registra en `bootstrap/providers.php`.

Verifica:

```bash
ls app/Providers/Filament/
grep AdminPanelProvider bootstrap/providers.php
```

Lee el provider generado. Vamos a customizarlo. Reescríbelo entero:

```php
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                // Cobalto Winfin (DESIGN.md §4) — único acento de marca.
                'primary' => Color::hex('#1D3F8C'),
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
```

Verifica que `bootstrap/providers.php` tiene la línea `App\Providers\Filament\AdminPanelProvider::class,`. Si `make:filament-panel` no la añadió (raro), añádela.

PARA: "Fase 2 completa: AdminPanelProvider configurado con cobalto + viteTheme. ¿Procedo a Fase 3 (theme CSS)?"

## FASE 3 — Theme custom Filament

Crea la estructura de carpetas:

```bash
mkdir -p resources/css/filament/admin
```

### 3a — `resources/css/filament/admin/theme.css`

```css
/* Filament admin theme — Winfin PIV (DESIGN.md §9 Aplicación al stack/Filament).
 *
 * Hereda los presets oficiales de Filament + reaplica los tokens DESIGN.md
 * (cobalto, Instrument Serif + General Sans, fondo cálido FAFAF7).
 */

@import "../../../../vendor/filament/filament/resources/css/theme.css";

/* Fuentes vía Bunny Fonts (mirror RGPD-friendly).
 * Justificación: tribunales europeos contra fonts.googleapis.com.
 */
@import url("https://fonts.bunny.net/css?family=instrument-serif:400,400i&display=swap");
@import url("https://api.fontshare.com/v2/css?f[]=general-sans@400,500,600,700&display=swap");

@config 'tailwind.config.js';

/* ---- Aplicación de los tokens DESIGN.md sobre el shell de Filament ----
 *
 * Filament define sus propios CSS variables (--primary-*, etc) — el preset
 * `colors(['primary' => Color::hex('#1D3F8C')])` del PanelProvider las regenera
 * a partir del cobalto. Aquí sobreescribimos solo las decisiones tipográficas
 * y de fondo que NO se cubren con `colors()`.
 */

:root {
    --fi-font-family: '"General Sans"', "ui-sans-serif", "system-ui", sans-serif;
    --fi-display-font-family: '"Instrument Serif"', "ui-serif", "Georgia", serif;
}

body.fi-body {
    font-family: var(--fi-font-family);
    background-color: #FAFAF7;
    color: #0F1115;
    font-variant-numeric: tabular-nums;
}

/* Titulares de página en Instrument Serif (DESIGN.md §3) */
.fi-header-heading,
.fi-section-header-heading,
.fi-page-heading {
    font-family: var(--fi-display-font-family);
    font-weight: 400;
    letter-spacing: -0.01em;
}

/* Cards con border-radius 8px (DESIGN.md §6 Border radius) */
.fi-section,
.fi-card {
    border-radius: 8px;
}

/* Status pills con padding compacto (DESIGN.md §10.3) */
.fi-badge {
    border-radius: 9999px;
}
```

### 3b — `resources/css/filament/admin/tailwind.config.js`

```js
import preset from "../../../../vendor/filament/support/tailwind.config.preset";

/** @type {import('tailwindcss').Config} */
export default {
    presets: [preset],
    content: [
        "./app/Filament/**/*.php",
        "./resources/views/filament/**/*.blade.php",
        "./vendor/filament/**/*.blade.php",
    ],
    theme: {
        extend: {
            // Fuentes DESIGN.md — alineadas al tailwind.config.js raíz pero
            // reaplicadas aquí porque Filament theme tiene su propio config.
            fontFamily: {
                sans:  ['"General Sans"', "ui-sans-serif", "system-ui", "sans-serif"],
                serif: ['"Instrument Serif"', "ui-serif", "Georgia", "serif"],
            },
        },
    },
};
```

PARA: "Fase 3 completa: theme.css + tailwind.config.js de Filament creados. ¿Procedo a Fase 4 (vite)?"

## FASE 4 — Actualizar Vite

Lee `vite.config.js`. Añade el theme al `input` array. Resultado esperado:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
```

Smoke build:

```bash
npm run build 2>&1 | tail -10
```

Verifica que aparece línea con `theme.css` en el output (público con hash). Si falla, AVISA con el error.

PARA: "Fase 4 completa: vite compila theme.css. ¿Procedo a Fase 5 (User implements FilamentUser)?"

## FASE 5 — User implements `FilamentUser`

Lee `app/Models/User.php`. Añade la interface y el método `canAccessPanel()`. El resultado:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Usuario unificado de la app nueva. Apunta a `lv_users`.
 *
 * Cada fila identifica unívocamente a un usuario legacy via (legacy_kind, legacy_id):
 *   - legacy_kind='admin'    -> u1.user_id
 *   - legacy_kind='tecnico'  -> tecnico.tecnico_id
 *   - legacy_kind='operador' -> operador.operador_id
 *
 * El email NO es único globalmente. Puede haber colisión cross-tabla (verificado
 * en Bloque 02: 1 caso real de info@winfin.es en tecnico Y operador). El guard
 * de auth resuelve por la ruta de login (/admin, /tecnico, /operador) — ver
 * ADR-0005 §3 y ADR-0008.
 *
 * Password puede ser NULL hasta el primer login post-migración (ADR-0003 lazy
 * SHA1->bcrypt). legacy_password_sha1 se popula al vuelo y se borra tras rehash.
 */
class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $table = 'lv_users';

    protected $fillable = [
        'legacy_kind', 'legacy_id', 'email', 'name', 'password',
        'legacy_password_sha1', 'lv_password_migrated_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'legacy_password_sha1',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'lv_password_migrated_at' => 'datetime',
            'password' => 'hashed',
            'legacy_id' => 'integer',
        ];
    }

    // ----------------------------------------------------------------
    // Helpers de rol
    // ----------------------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->legacy_kind === 'admin';
    }

    public function isTecnico(): bool
    {
        return $this->legacy_kind === 'tecnico';
    }

    public function isOperador(): bool
    {
        return $this->legacy_kind === 'operador';
    }

    /**
     * Devuelve el modelo legacy correspondiente (U1, Tecnico, Operador).
     */
    public function legacyEntity(): ?\Illuminate\Database\Eloquent\Model
    {
        return match ($this->legacy_kind) {
            'admin'    => U1::find($this->legacy_id),
            'tecnico'  => Tecnico::find($this->legacy_id),
            'operador' => Operador::find($this->legacy_id),
            default    => null,
        };
    }

    // ----------------------------------------------------------------
    // Filament gate (Bloque 05)
    // ----------------------------------------------------------------

    /**
     * Solo admins entran al panel `admin`. Si en el futuro hay panel
     * `tecnico` u `operador`, discriminamos por $panel->getId().
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->isAdmin(),
            default => false,
        };
    }
}
```

PARA: "Fase 5 completa: User implements FilamentUser con canAccessPanel restringido a admin. ¿Procedo a Fase 6 (tests)?"

## FASE 6 — Tests

### 6a — `tests/Feature/Filament/AdminPanelTest.php`

```php
<?php

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers the admin panel with id "admin"', function () {
    expect(Filament::getPanel('admin'))->not->toBeNull();
});

it('admin panel uses path "admin"', function () {
    expect(Filament::getPanel('admin')->getPath())->toBe('admin');
});

it('admin panel is the default panel', function () {
    expect(Filament::getDefaultPanel()->getId())->toBe('admin');
});

it('admin panel theme points to resources/css/filament/admin/theme.css', function () {
    // Lectura estática del provider para verificar la cadena.
    $source = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
    expect($source)->toContain("'resources/css/filament/admin/theme.css'");
});

it('admin panel has primary color cobalto', function () {
    $source = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
    expect($source)->toContain("'#1D3F8C'");
});

it('login route GET /admin/login responds 200', function () {
    $this->get('/admin/login')->assertOk();
});
```

### 6b — `tests/Feature/Filament/CanAccessPanelTest.php`

```php
<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('admin can access the admin panel', function () {
    $user = User::factory()->admin()->create();
    $panel = Filament::getPanel('admin');
    expect($user->canAccessPanel($panel))->toBeTrue();
});

it('tecnico cannot access the admin panel', function () {
    $user = User::factory()->tecnico()->create();
    $panel = Filament::getPanel('admin');
    expect($user->canAccessPanel($panel))->toBeFalse();
});

it('operador cannot access the admin panel', function () {
    $user = User::factory()->operador()->create();
    $panel = Filament::getPanel('admin');
    expect($user->canAccessPanel($panel))->toBeFalse();
});

it('non-admin authenticated user gets 403 visiting /admin', function () {
    $tecnico = User::factory()->tecnico()->create();
    $this->actingAs($tecnico)->get('/admin')->assertForbidden();
});

it('admin authenticated user gets through to /admin', function () {
    $admin = User::factory()->admin()->create();
    // Filament dashboard responde 200 o redirige a página interna; ambos OK.
    $response = $this->actingAs($admin)->get('/admin');
    expect($response->status())->toBeIn([200, 302]);
});
```

Corre tests:

```bash
./vendor/bin/pest --colors=never --compact 2>&1 | tail -20
```

Si verde sigue. Si falla, AVISA con el error exacto.

PARA: "Fase 6 completa: tests Filament verdes. ¿Procedo a Fase 7 (pint + smoke final)?"

## FASE 7 — pint + smoke final

```bash
./vendor/bin/pint --test 2>&1 | tail -5
./vendor/bin/pest --colors=never --compact 2>&1 | tail -10
npm run build 2>&1 | tail -5
```

Si Pint reporta archivos a corregir, corre `./vendor/bin/pint` (sin `--test`). Esos cambios irán en commit aparte tipo `style:`.

Verifica que `npm run build` produce hash file para `theme.css` en `public/build/assets/`.

PARA: "Fase 7 completa: pint + pest + build verdes. ¿Procedo a Fase 8 (runbook + commits + PR)?"

## FASE 8 — Runbook post-merge + commits + push + PR

### 8a — Crear runbook

Crea `docs/runbooks/05-prod-deploy-filament.md`:

```markdown
# Runbook 05 — Deploy Filament a producción + primer admin

> Se ejecuta DESPUÉS de mergear PR del Bloque 05. Lo hace Claude Code o el usuario
> vía SSH; NO lo hace Copilot. Toca BD producción.

## Prerequisitos

- PR Bloque 05 mergeado en `main`.
- Backup reciente de la BD prod (mysqldump < 24h). Si no hay, crear uno antes.
- Confirmación humana explícita (la migración crea tablas nuevas en prod, no toca legacy).

## Pasos

### 1. Backup fresh de la BD prod

```bash
ssh u1234@server.siteground.com
cd ~/private/backups
mysqldump --defaults-extra-file=~/.my.cnf \
    --single-transaction --quick --no-tablespaces \
    dbvnxblp2rzlxj > prod-pre-bloque-05-$(date +%Y%m%d-%H%M%S).sql
gzip prod-pre-bloque-05-*.sql
ls -lh prod-pre-bloque-05-*.sql.gz
```

### 2. Verificar que las migrations lv_* no tocan tablas legacy

Localmente (Mac), antes de ejecutar nada en prod:

```bash
cd ~/Documents/winfin-piv
git pull
ls database/migrations/
# Esperado: 0001_01_01_000000_create_users_table.php (lv_users)
#           0001_01_01_000001_create_cache_table.php (lv_cache)
#           0001_01_01_000002_create_jobs_table.php (lv_jobs)
#           2026_05_01_000000_create_lv_correctivo_imagen_table.php
```

Las 4 migrations crean exclusivamente tablas con prefijo `lv_*`. Sin `ALTER` ni `DROP` sobre legacy.

### 3. Ejecutar migrate contra prod

Con `.env` LOCAL apuntando a SiteGround MySQL:

```bash
php artisan migrate --pretend           # dry-run, ver SQL
# Confirmar visualmente que solo hay CREATE TABLE lv_*

php artisan migrate                      # ejecutar
php artisan migrate:status               # confirmar las 4 marcadas como Ran
```

### 4. Verificar tablas en prod

```bash
mysql --defaults-extra-file=~/.my.cnf -e \
    "SHOW TABLES FROM dbvnxblp2rzlxj LIKE 'lv\_%';"
```

Esperado: 9 tablas (`lv_users`, `lv_password_reset_tokens`, `lv_sessions`,
`lv_cache`, `lv_cache_locks`, `lv_jobs`, `lv_job_batches`, `lv_failed_jobs`,
`lv_correctivo_imagen`).

### 5. Crear primer admin vía tinker

```bash
php artisan tinker
```

Dentro:

```php
$u1 = \App\Models\U1::first();
echo "u1.user_id = {$u1->user_id}, email = {$u1->email}, username = {$u1->username}\n";

$admin = \App\Models\User::create([
    'legacy_kind'              => 'admin',
    'legacy_id'                => $u1->user_id,
    'email'                    => $u1->email,                          // o el que prefiera el usuario
    'name'                     => $u1->username,                       // ajustable
    'password'                 => '<PASSWORD-NUEVO-CHOSEN>',           // bcrypt automático por cast 'hashed'
    'legacy_password_sha1'     => null,
    'lv_password_migrated_at'  => now(),
    'email_verified_at'        => now(),
]);
echo "lv_users.id = {$admin->id}\n";
exit
```

NOTAS:
- El password lo elige el admin en este momento; NO se reutiliza el SHA1 de `u1.password`.
  Por eso `legacy_password_sha1=null` y `lv_password_migrated_at=now()`: este admin ya está
  "post-migración" desde día uno. Bloque 06 no tocará a este usuario.
- Si se cambia de email respecto a `u1.email`, perfecto — el lookup de Bloque 06 será por
  `(legacy_kind, legacy_id)`, no por email.

### 6. Verificar login

Mac → navegador → `https://piv.winfin.es/admin/login`.

Login con email + password elegidos. Esperado: dashboard Filament con tema cobalto + Instrument Serif visibles.

Si falla:
- 419 CSRF mismatch → `php artisan optimize:clear` en prod.
- 500 → `tail -50 storage/logs/laravel.log`.
- Login redirige a sí mismo → password no quedó bcrypt; verificar `User::first()->password` empieza por `$2y$`.

### 7. Cerrar runbook

Apuntar en `docs/runbooks/05-prod-deploy-filament.md` la fecha de ejecución
y el hash del backup pre-deploy. Actualizar `memory/status.md` con el resultado.
```

### 8b — Commits separados

Stage explícito por archivo. Estructura sugerida:

1. `docs: add Bloque 05 prompt (Filament install + theme + canAccessPanel)`
   — solo `docs/prompts/05-filament-install.md`.
2. `chore: install filament/filament ^3.2`
   — `composer.json` + `composer.lock`.
3. `feat(filament): create admin panel with cobalto + DESIGN.md theme`
   — `app/Providers/Filament/AdminPanelProvider.php` + `bootstrap/providers.php`.
4. `feat(theme): add Filament admin theme with Instrument Serif + General Sans`
   — `resources/css/filament/admin/theme.css` + `resources/css/filament/admin/tailwind.config.js`.
5. `chore(vite): register Filament admin theme entry`
   — `vite.config.js`.
6. `feat(auth): User implements FilamentUser with canAccessPanel admin gate`
   — `app/Models/User.php`.
7. `test: cover Filament panel registration and canAccessPanel logic`
   — `tests/Feature/Filament/AdminPanelTest.php` + `tests/Feature/Filament/CanAccessPanelTest.php`.
8. `docs: add runbook for prod migrate + first admin (Bloque 05)`
   — `docs/runbooks/05-prod-deploy-filament.md`.

Smoke final:

```bash
./vendor/bin/pest --colors=never --compact 2>&1 | tail -5
git push -u origin bloque-05-filament-install
```

### 8c — Crear PR

```bash
gh pr create \
  --base main \
  --head bloque-05-filament-install \
  --title "Bloque 05 — Filament 3.2 + theme DESIGN.md + canAccessPanel admin gate" \
  --body "$(cat <<'BODY'
## Resumen

Instala Filament 3.2 con el panel admin en `/admin`. Aplica el sistema visual DESIGN.md (cobalto `#1D3F8C`, Instrument Serif + General Sans vía Bunny Fonts, fondo `#FAFAF7`). Restringe acceso al panel a usuarios con `legacy_kind='admin'`.

## Qué entra

- `composer require filament/filament:"^3.2"` con PHP 8.2 floor respetado.
- `app/Providers/Filament/AdminPanelProvider.php` con `id('admin')`, `path('admin')`, `colors([primary => cobalto])`, `viteTheme()`.
- `resources/css/filament/admin/theme.css` que importa el preset oficial Filament + Bunny Fonts + tokens.
- `resources/css/filament/admin/tailwind.config.js` extendiendo presets Filament.
- `vite.config.js` con entry para el theme admin.
- `App\Models\User` ahora implementa `Filament\Models\Contracts\FilamentUser` con `canAccessPanel()` que solo permite admin.
- 11 tests Pest cubriendo registro de panel + theme path + canAccessPanel para cada rol + 403 en `/admin` para non-admin.
- Runbook `docs/runbooks/05-prod-deploy-filament.md` con pasos exactos para deploy a prod.

## Qué NO entra

- `php artisan migrate` contra MySQL prod — runbook separado, lo ejecuta Claude/usuario tras merge.
- Crear primer admin en `lv_users` — runbook separado.
- Login de técnicos / operadores y `LegacyHashGuard` — Bloque 06.
- Filament resources de `Piv`, `Averia`, etc — Bloques 07-09.

## Tests obligatorios

- `admin can access the admin panel` ✓
- `tecnico cannot access the admin panel` ✓
- `operador cannot access the admin panel` ✓
- `non-admin authenticated user gets 403 visiting /admin` ✓
- `login route GET /admin/login responds 200` ✓

## CI esperado

3/3 jobs verde (PHP 8.2, PHP 8.3, Vite build).
BODY
)"
```

Espera CI:

```bash
sleep 8
PR_NUM=$(gh pr list --head bloque-05-filament-install --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

Cuando todo verde:

```
✅ Qué he hecho:
   - Filament 3.2 instalado.
   - Panel `admin` configurado con cobalto + theme DESIGN.md.
   - User implements FilamentUser con canAccessPanel = isAdmin().
   - 11 tests Pest verdes.
   - Pint + build OK.
   - 8 commits Conventional Commits.
   - PR #N: [URL].
   - CI 3/3 verde.
   - Runbook post-merge en docs/runbooks/05-prod-deploy-filament.md.

⏳ Qué falta:
   - (Post-merge, fuera de Copilot) Backup prod + migrate prod + crear primer admin vía tinker + verificar login real.
   - Bloque 06 — LegacyHashGuard para tecnico/operador.

❓ Qué necesito del usuario:
   - Confirmar URL del PR.
   - Mergear cuando esté revisado (sugiero Rebase and merge).
   - Tras merge, ejecutar el runbook 05 con Claude Code.
```

NO mergees el PR.

END PROMPT
```

---

## Después de Bloque 05 (Claude Code, no Copilot)

1. Ejecutar `docs/runbooks/05-prod-deploy-filament.md` paso a paso. Entrar primero por SSH a verificar que el backup pre-deploy se generó correcto, luego `migrate`, luego tinker para crear el admin, luego verificar login en navegador.
2. Actualizar `memory/status.md` con resultado del deploy.
3. Pasar a **Bloque 06** — `LegacyHashGuard::attempt()` con mapeo `$tableMeta` (ADR-0008) + lazy creation (ADR-0005) + rate limiting + 7 tests obligatorios. Sesión dedicada por la complejidad.
