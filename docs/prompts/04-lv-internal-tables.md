# Bloque 04 — Migrations `lv_*` para tablas internas Laravel

> **Cómo se usa:** copia el bloque `BEGIN PROMPT` … `END PROMPT` y pégalo en VS Code Copilot Chat (modo Agent). ~25-30 min.

---

## Objetivo

Crear las migraciones para las **tablas internas Laravel con prefijo `lv_`** (ADR-0002 coexistencia BD: tablas legacy intactas, tablas nuevas separadas con prefix `lv_`).

Tablas a crear:

| Tabla | Origen / Razón |
|---|---|
| `lv_users` | Auth unificado, schema completo de ADR-0005 (`legacy_kind`, `legacy_id`, etc). |
| `lv_password_reset_tokens` | Reset de password Laravel default. |
| `lv_sessions` | Driver de sesión `database` (Laravel default + prefix). |
| `lv_cache`, `lv_cache_locks` | Driver de cache `database`. |
| `lv_jobs`, `lv_job_batches`, `lv_failed_jobs` | Queue driver `database`. |
| `lv_correctivo_imagen` | Fotos asociadas a cierre de correctivo, schema de ADR-0006. |

**Pendientes para bloques siguientes (NO en Bloque 04):**
- `lv_personal_access_tokens` → solo si instalamos Sanctum (no planificado todavía).
- `lv_notifications` → Bloque 13 (push notifications).
- `lv_webpush_subscriptions` → Bloque 13 (paquete `laravel-notification-channels/webpush` publica su propia migración).

**Configs a actualizar:**
- `config/session.php` → `table: lv_sessions`.
- `config/cache.php` → `table: lv_cache`, `lock_table: lv_cache_locks`.
- `config/queue.php` → `database.table: lv_jobs`, `failed.table: lv_failed_jobs`.
- `config/auth.php` → ya apunta a `App\Models\User`, no requiere cambios (el modelo se actualiza para usar `lv_users`).

**Modelos a tocar:**
- `App\Models\User` — actualizar `$table`, `$fillable`, `$hidden`, `$casts` según ADR-0005. Añadir helpers `isAdmin()`, `isTecnico()`, `isOperador()`.
- `App\Models\LvCorrectivoImagen` — nuevo modelo (ADR-0006).
- `App\Models\Correctivo` — añadir relación `imagenes() HasMany LvCorrectivoImagen` (mencionada en ADR-0006 pero no implementada en Bloque 03).

**Definition of Done:**
1. 4 archivos de migration en `database/migrations/` con tablas `lv_*`. Las 3 default Laravel reescritas + 1 nuevo `lv_correctivo_imagen`.
2. `App\Models\User` apunta a `lv_users` con campos ADR-0005.
3. `App\Models\LvCorrectivoImagen` creado con relación a `Correctivo`.
4. `App\Models\Correctivo::imagenes()` añadido.
5. `config/{session,cache,queue}.php` apuntan a `lv_*`.
6. Migrations corren limpio en SQLite memory (CI).
7. Smoke tests: User CRUD, LvCorrectivoImagen CRUD, schema verification.
8. `pint --test`, `pest`, `npm run build` verdes.
9. PR creado, CI 3/3 verde.

---

## Riesgos y mitigaciones

- **Modificar las migrations default Laravel del Bloque 01** (que ya están en main vía PR Bloque 01). Mitigación: editamos los archivos en sitio cambiando `users → lv_users` etc. La historia git mostrará el cambio claro.
- **`App\Models\User` ya existe por defecto** y es trivial. Lo modificamos para que use `$table = 'lv_users'` + campos custom. Cualquier código futuro que use `User` (Filament Auth, etc.) ya apunta al sitio correcto.
- **Las legacy_test migrations del Bloque 03** se cargan vía `AppServiceProvider` solo en `APP_ENV=testing`. Las migrations `lv_*` del Bloque 04 sí se cargan también en producción cuando se haga `php artisan migrate`. Esto es correcto y esperado.
- **Tests del Bloque 03 usan factories que pueden chocar con `lv_users`**: ninguna factory de Bloque 03 inserta en `users`/`lv_users`, solo en tablas legacy. Sin conflicto esperado.
- **`User` es el único modelo Authenticatable**: tras Bloque 04 puede crearse un user en `lv_users` con `legacy_kind='admin'` apuntando a `u1.user_id`. El guard custom de Bloque 06 lo usará. Por ahora solo verificamos que el modelo funciona.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md (convenciones)
- CLAUDE.md (división trabajo)
- docs/decisions/0002-database-coexistence.md (ADR coexistencia)
- docs/decisions/0005-user-unification.md (schema lv_users)
- docs/decisions/0006-correctivo-schema-strategy.md (schema lv_correctivo_imagen)
- docs/prompts/04-lv-internal-tables.md (este archivo, secciones objetivo y riesgos)

Tu tarea: implementar el Bloque 04. Crear migrations para 6 tablas internas Laravel con prefijo `lv_*` + actualizar configs + modelos.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch

```bash
pwd                              # /Users/winfin/Documents/winfin-piv
git branch --show-current        # main
git rev-parse HEAD               # debe ser e3ce874 (post Bloque 03)
git status --short               # vacío
./vendor/bin/pest --version      # Pest 3.x
```

Si algo no encaja, AVISA y para.

```bash
git checkout -b bloque-04-lv-internal-tables
```

PARA: "Branch creada. ¿Procedo a Fase 1 (reescribir migrations default → lv_*)?"

## FASE 1 — Reescribir las 3 migrations default Laravel a `lv_*`

Las 3 migrations default que vienen con Laravel 12 son:

- `database/migrations/0001_01_01_000000_create_users_table.php` — crea `users`, `password_reset_tokens`, `sessions`.
- `database/migrations/0001_01_01_000001_create_cache_table.php` — crea `cache`, `cache_locks`.
- `database/migrations/0001_01_01_000002_create_jobs_table.php` — crea `jobs`, `job_batches`, `failed_jobs`.

Lee cada una primero para confirmar contenido. Luego reescribe:

### 1a — `0001_01_01_000000_create_users_table.php`

Reemplaza contenido entero. **`lv_users` schema custom según ADR-0005**:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // lv_users: auth unificado de los 3 roles legacy (u1, tecnico, operador).
        // Schema según ADR-0005: lookup canónico por (legacy_kind, legacy_id),
        // email no único (puede haber colisión cross-tabla, ya verificado en
        // Bloque 02). Password nullable hasta primer login post-migración (ADR-0003).
        Schema::create('lv_users', function (Blueprint $t) {
            $t->id();
            $t->enum('legacy_kind', ['admin', 'tecnico', 'operador']);
            $t->unsignedInteger('legacy_id');
            $t->string('email');
            $t->string('name');
            $t->string('password')->nullable();                    // bcrypt; null hasta primer login
            $t->char('legacy_password_sha1', 40)->nullable();      // copia del SHA1 legacy; se borra al rehash
            $t->timestamp('lv_password_migrated_at')->nullable();
            $t->rememberToken();
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamps();

            $t->unique(['legacy_kind', 'legacy_id'], 'uniq_legacy');
            $t->index('email', 'idx_email');
        });

        Schema::create('lv_password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('lv_sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_sessions');
        Schema::dropIfExists('lv_password_reset_tokens');
        Schema::dropIfExists('lv_users');
    }
};
```

### 1b — `0001_01_01_000001_create_cache_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_cache', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });

        Schema::create('lv_cache_locks', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_cache_locks');
        Schema::dropIfExists('lv_cache');
    }
};
```

### 1c — `0001_01_01_000002_create_jobs_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });

        Schema::create('lv_job_batches', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->integer('total_jobs');
            $t->integer('pending_jobs');
            $t->integer('failed_jobs');
            $t->longText('failed_job_ids');
            $t->mediumText('options')->nullable();
            $t->integer('cancelled_at')->nullable();
            $t->integer('created_at');
            $t->integer('finished_at')->nullable();
        });

        Schema::create('lv_failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_failed_jobs');
        Schema::dropIfExists('lv_job_batches');
        Schema::dropIfExists('lv_jobs');
    }
};
```

PARA: "Fase 1 completa: 3 migrations default reescritas con lv_*. ¿Procedo a Fase 2 (lv_correctivo_imagen)?"

## FASE 2 — Migration nueva `lv_correctivo_imagen` (ADR-0006)

Crea `database/migrations/2026_05_01_000000_create_lv_correctivo_imagen_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla nueva para fotos asociadas al cierre de un correctivo.
 *
 * Schema según ADR-0006 (correctivo schema reuse strategy). NO va en la
 * tabla legacy `correctivo` (que no tiene columna `imagen`) ni se mete en
 * `piv_imagen` (que es por panel, no por cierre concreto).
 *
 * Sin FK física a `correctivo` (regla coexistencia ADR-0002: no constraints
 * que apunten a tablas legacy). La integridad referencial se valida en app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_correctivo_imagen', function (Blueprint $t) {
            $t->id();
            $t->integer('correctivo_id');                          // FK lógica a correctivo.correctivo_id
            $t->string('url', 500);                                // path en storage/app/public/piv-images/correctivo/
            $t->unsignedTinyInteger('posicion')->default(1);       // orden de las fotos del cierre
            $t->timestamps();

            $t->index('correctivo_id', 'idx_correctivo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_correctivo_imagen');
    }
};
```

PARA: "Fase 2 completa: lv_correctivo_imagen migration. ¿Procedo a Fase 3 (User model)?"

## FASE 3 — Update `App\Models\User`

Lee `app/Models/User.php` actual (Laravel default). Reescríbelo según ADR-0005:

```php
<?php

declare(strict_types=1);

namespace App\Models;

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
class User extends Authenticatable
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
     * Útil para acceder a campos que viven en la tabla origen.
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
}
```

Actualiza la factory `database/factories/UserFactory.php` (existe por default Laravel) para usar campos lv_users:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            'legacy_kind' => 'tecnico',
            'legacy_id' => $sequence,
            'email' => $this->faker->unique()->safeEmail(),
            'name' => $this->faker->name(),
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(['legacy_kind' => 'admin']);
    }

    public function tecnico(): static
    {
        return $this->state(['legacy_kind' => 'tecnico']);
    }

    public function operador(): static
    {
        return $this->state(['legacy_kind' => 'operador']);
    }

    public function legacyOnlyNoBcrypt(): static
    {
        return $this->state([
            'password' => null,
            'legacy_password_sha1' => sha1('legacypwd'),
            'lv_password_migrated_at' => null,
        ]);
    }
}
```

PARA: "Fase 3 completa: User apunta a lv_users con helpers de rol. ¿Procedo a Fase 4 (configs)?"

## FASE 4 — Update configs apuntando a lv_*

Edita los siguientes archivos de config:

### 4a — `config/session.php`

Encuentra la línea con `'table' => env('SESSION_TABLE', 'sessions')` y cámbiala a:

```php
'table' => env('SESSION_TABLE', 'lv_sessions'),
```

### 4b — `config/cache.php`

En la sección `'database' => [`, cambia:

```php
'database' => [
    'driver' => 'database',
    'connection' => env('DB_CACHE_CONNECTION'),
    'table' => env('DB_CACHE_TABLE', 'lv_cache'),
    'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
    'lock_table' => env('DB_CACHE_LOCK_TABLE', 'lv_cache_locks'),
],
```

### 4c — `config/queue.php`

En la sección `'database' => [`:

```php
'database' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'lv_jobs'),
    'queue' => env('DB_QUEUE', 'default'),
    'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
    'after_commit' => false,
],
```

Y en la sección `'failed' => [`:

```php
'failed' => [
    'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
    'database' => env('DB_CONNECTION', 'sqlite'),
    'table' => 'lv_failed_jobs',
],
```

### 4d — `config/auth.php`

Verifica que existe el provider `users` apuntando a `App\Models\User`. NO requiere cambios — User ya apunta a `lv_users` por nuestro $table.

### 4e — `.env.example`

Si tiene líneas tipo `SESSION_TABLE=`, `DB_CACHE_TABLE=`, `DB_QUEUE_TABLE=` con valores explícitos, actualiza:
```
SESSION_TABLE=lv_sessions
DB_CACHE_TABLE=lv_cache
DB_CACHE_LOCK_TABLE=lv_cache_locks
DB_QUEUE_TABLE=lv_jobs
```

Si NO tiene esas líneas (lo más probable), no añadas — los defaults de los configs ya son `lv_*`.

PARA: "Fase 4 completa: configs apuntan a lv_*. ¿Procedo a Fase 5 (LvCorrectivoImagen model)?"

## FASE 5 — Modelo `LvCorrectivoImagen` + relación en `Correctivo`

Crea `app/Models/LvCorrectivoImagen.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto asociada al cierre de un correctivo (ADR-0006).
 *
 * NO es la tabla legacy `piv_imagen` (esa es por panel). Esta es por cierre
 * concreto. Sin FK física a correctivo (regla coexistencia ADR-0002), la
 * integridad la valida la app.
 */
class LvCorrectivoImagen extends Model
{
    use HasFactory;

    protected $table = 'lv_correctivo_imagen';

    protected $fillable = ['correctivo_id', 'url', 'posicion'];

    protected $casts = [
        'correctivo_id' => 'integer',
        'posicion' => 'integer',
    ];

    public function correctivo(): BelongsTo
    {
        return $this->belongsTo(Correctivo::class, 'correctivo_id', 'correctivo_id');
    }
}
```

Crea `database/factories/LvCorrectivoImagenFactory.php` siguiendo el patrón del Bloque 03.

Edita `app/Models/Correctivo.php` añadiendo la relación `imagenes()`. Lee primero el archivo, busca dónde están las otras relaciones (`asignacion()`, `tecnico()`), y añade después:

```php
    public function imagenes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LvCorrectivoImagen::class, 'correctivo_id', 'correctivo_id')
            ->orderBy('posicion');
    }
```

Asegúrate de que el `use Illuminate\Database\Eloquent\Relations\HasMany;` está al top del archivo (probablemente ya).

PARA: "Fase 5 completa: LvCorrectivoImagen + relación en Correctivo. ¿Procedo a Fase 6 (tests)?"

## FASE 6 — Tests

### 6a — `tests/Feature/Migrations/LvTablesTest.php`

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the 8 lv_* tables', function () {
    foreach ([
        'lv_users', 'lv_password_reset_tokens', 'lv_sessions',
        'lv_cache', 'lv_cache_locks',
        'lv_jobs', 'lv_job_batches', 'lv_failed_jobs',
        'lv_correctivo_imagen',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Tabla {$table} no existe");
    }
});

it('lv_users has columns according to ADR-0005', function () {
    foreach ([
        'id', 'legacy_kind', 'legacy_id', 'email', 'name',
        'password', 'legacy_password_sha1', 'lv_password_migrated_at',
        'remember_token', 'email_verified_at', 'created_at', 'updated_at',
    ] as $col) {
        expect(Schema::hasColumn('lv_users', $col))->toBeTrue("Columna {$col} falta en lv_users");
    }
});

it('lv_correctivo_imagen has columns according to ADR-0006', function () {
    foreach (['id', 'correctivo_id', 'url', 'posicion', 'created_at', 'updated_at'] as $col) {
        expect(Schema::hasColumn('lv_correctivo_imagen', $col))->toBeTrue();
    }
});
```

### 6b — `tests/Feature/Models/UserTest.php`

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates and reads from lv_users', function () {
    $u = User::factory()->create([
        'legacy_kind' => 'admin',
        'legacy_id' => 1,
        'email' => 'admin@winfin.local',
        'name' => 'Admin Test',
    ]);
    $found = User::where('email', 'admin@winfin.local')->first();
    expect($found)->not->toBeNull();
    expect($found->legacy_kind)->toBe('admin');
});

it('hides password and legacy_password_sha1 from serialization', function () {
    $u = User::factory()->create();
    $arr = $u->toArray();
    expect($arr)->not->toHaveKey('password');
    expect($arr)->not->toHaveKey('legacy_password_sha1');
    expect($arr)->not->toHaveKey('remember_token');
});

it('exposes role helpers', function () {
    expect(User::factory()->admin()->make()->isAdmin())->toBeTrue();
    expect(User::factory()->tecnico()->make()->isTecnico())->toBeTrue();
    expect(User::factory()->operador()->make()->isOperador())->toBeTrue();
});

it('enforces unique (legacy_kind, legacy_id)', function () {
    User::factory()->create(['legacy_kind' => 'tecnico', 'legacy_id' => 5]);
    expect(fn () => User::factory()->create(['legacy_kind' => 'tecnico', 'legacy_id' => 5]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows same email across different legacy_kind (cross-tabla colision)', function () {
    User::factory()->create(['legacy_kind' => 'tecnico', 'legacy_id' => 10, 'email' => 'shared@winfin.local']);
    $op = User::factory()->create(['legacy_kind' => 'operador', 'legacy_id' => 20, 'email' => 'shared@winfin.local']);
    expect($op)->not->toBeNull();
});
```

### 6c — `tests/Feature/Models/LvCorrectivoImagenTest.php`

```php
<?php

use App\Models\Correctivo;
use App\Models\LvCorrectivoImagen;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates and reads', function () {
    $img = LvCorrectivoImagen::factory()->create([
        'correctivo_id' => 1,
        'url' => 'storage/app/public/piv-images/correctivo/test.jpg',
    ]);
    expect(LvCorrectivoImagen::find($img->id))->not->toBeNull();
});

it('relates to a Correctivo via correctivo_id', function () {
    $c = Correctivo::factory()->create(['correctivo_id' => 100]);
    $img = LvCorrectivoImagen::factory()->create(['correctivo_id' => 100]);
    expect($img->correctivo->correctivo_id)->toBe(100);
});

it('Correctivo->imagenes() returns ordered HasMany', function () {
    $c = Correctivo::factory()->create(['correctivo_id' => 200]);
    LvCorrectivoImagen::factory()->create(['correctivo_id' => 200, 'posicion' => 3]);
    LvCorrectivoImagen::factory()->create(['correctivo_id' => 200, 'posicion' => 1]);
    LvCorrectivoImagen::factory()->create(['correctivo_id' => 200, 'posicion' => 2]);
    $imgs = $c->fresh()->imagenes;
    expect($imgs)->toHaveCount(3);
    expect($imgs[0]->posicion)->toBe(1);
    expect($imgs[2]->posicion)->toBe(3);
});
```

### 6d — `tests/Feature/ConfigTest.php`

```php
<?php

it('session config points to lv_sessions', function () {
    expect(config('session.table'))->toBe('lv_sessions');
});

it('cache database config points to lv_cache + lv_cache_locks', function () {
    expect(config('cache.stores.database.table'))->toBe('lv_cache');
    expect(config('cache.stores.database.lock_table'))->toBe('lv_cache_locks');
});

it('queue database config points to lv_jobs', function () {
    expect(config('queue.connections.database.table'))->toBe('lv_jobs');
});

it('failed queue config points to lv_failed_jobs', function () {
    expect(config('queue.failed.table'))->toBe('lv_failed_jobs');
});
```

Corre todos los tests:

```bash
./vendor/bin/pest --colors=never --compact 2>&1 | tail -15
```

Verde. PARA: "Fase 6 completa: tests verdes. ¿Procedo a Fase 7 (pint + smoke final)?"

## FASE 7 — pint + smoke final

```bash
./vendor/bin/pint --test 2>&1 | tail -5
./vendor/bin/pest --colors=never --compact 2>&1 | tail -10
npm run build 2>&1 | tail -3
```

Si Pint reporta algo, corre `./vendor/bin/pint` (sin --test) y commitea como style en commit aparte.

Si todo verde, sigue. PARA: "Fase 7 completa: pint + pest + build verdes. ¿Procedo a Fase 8 (commits + push + PR)?"

## FASE 8 — Commits + push + PR

Crea commits separados (NO `git add .`, stage explícito por archivo). Estructura:

1. `docs: add Bloque 04 prompt (lv_* internal tables)` — solo `docs/prompts/04-lv-internal-tables.md`.
2. `feat(migrations): rewrite default migrations with lv_* prefix (ADR-0002)` — los 3 archivos `0001_01_01_*` modificados.
3. `feat(migrations): add lv_correctivo_imagen (ADR-0006)` — el archivo nuevo.
4. `feat(models): point User at lv_users with ADR-0005 schema and role helpers` — `app/Models/User.php` + `database/factories/UserFactory.php`.
5. `feat(models): add LvCorrectivoImagen and Correctivo.imagenes() relation` — los modelos + factory + edición de Correctivo.
6. `chore(config): point session/cache/queue at lv_* tables` — los 3 configs (+ .env.example si tocado).
7. `test: verify lv_* tables and User+LvCorrectivoImagen behaviour` — los 4 archivos de tests.

Tras commits:

```bash
./vendor/bin/pest --colors=never --compact 2>&1 | tail -5   # smoke final
git push -u origin bloque-04-lv-internal-tables
```

Crea PR:

```bash
gh pr create \
  --base main \
  --head bloque-04-lv-internal-tables \
  --title "Bloque 04 — lv_* internal Laravel tables (users + cache + queue + correctivo_imagen)" \
  --body "$(cat <<'BODY'
## Resumen

Crea las tablas internas Laravel con prefijo lv_* según ADR-0002 (coexistencia BD: tablas legacy intactas, tablas Laravel separadas con lv_).

## Tablas creadas

- lv_users — auth unificado (schema ADR-0005: legacy_kind, legacy_id, password nullable, etc.).
- lv_password_reset_tokens — reset password Laravel default.
- lv_sessions — driver de sesión database.
- lv_cache + lv_cache_locks — driver de cache database.
- lv_jobs + lv_job_batches + lv_failed_jobs — queue driver database.
- lv_correctivo_imagen — fotos asociadas al cierre de correctivo (ADR-0006).

## Configs actualizados

- config/session.php → lv_sessions.
- config/cache.php → lv_cache + lv_cache_locks.
- config/queue.php → lv_jobs + lv_failed_jobs.
- config/auth.php → ya apuntaba a App\Models\User (User ahora apunta a lv_users vía $table).

## Modelos tocados

- App\Models\User — apunta a lv_users con campos ADR-0005, helpers isAdmin/isTecnico/isOperador, accessor legacyEntity().
- App\Models\LvCorrectivoImagen NUEVO — BelongsTo Correctivo.
- App\Models\Correctivo — añadida relación imagenes() HasMany ordenada por posicion.

## Pendientes para bloques siguientes (NO en este PR)

- lv_personal_access_tokens — solo si se instala Sanctum.
- lv_notifications — Bloque 13 (push notifications).
- lv_webpush_subscriptions — Bloque 13 (paquete laravel-notification-channels/webpush publica su migración).

## Tests

- N tests Feature pasando localmente (lv_* tables creation, User CRUD, LvCorrectivoImagen relation, configs apuntando lv_*).
- Pint clean. Vite build OK.

## CI esperado

3/3 jobs verde (PHP 8.2, PHP 8.3, Vite).
BODY
)"
```

Espera CI verde:

```bash
sleep 8
PR_NUM=$(gh pr list --head bloque-04-lv-internal-tables --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

Cuando todo verde:

```
✅ Qué he hecho:
   - 4 migrations (3 default reescritas + 1 nuevo lv_correctivo_imagen).
   - 8 tablas lv_* creadas.
   - User apunta a lv_users con campos ADR-0005 + helpers.
   - LvCorrectivoImagen + Correctivo.imagenes().
   - 3 configs (session, cache, queue) apuntan a lv_*.
   - 7 commits Conventional Commits.
   - PR #N: [URL].
   - CI 3/3 verde.

⏳ Qué falta:
   - Bloque 05 (Filament install + custom theme + primer admin via tinker).
   - Bloque 06 (LegacyHashGuard).

❓ Qué necesito del usuario:
   - Confirmar URL del PR.
   - Mergear cuando esté revisado (sugiero Rebase and merge).
```

NO mergees el PR.

END PROMPT
```

---

## Después de Bloque 04

- **Bloque 05** — `composer require filament/filament` + custom theme con tokens DESIGN.md (cobalto + Instrument Serif + General Sans) + crear primer admin user vía `php artisan tinker` (insert manual en `lv_users` con `legacy_kind='admin'` + `legacy_id=u1.user_id` + `password = bcrypt(...)`).
- **Bloque 06** — `LegacyHashGuard::attempt()` con mapeo `$tableMeta` (ADR-0008) + lazy creation (ADR-0005) + rate limiting + 7 tests obligatorios.
