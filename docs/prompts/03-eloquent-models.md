# Bloque 03 — Eloquent models para las 14 tablas legacy

> **Cómo se usa este archivo:** copia el bloque `BEGIN PROMPT` … `END PROMPT` y pégalo en VS Code Copilot Chat (modo Agent) con la carpeta `~/Documents/winfin-piv/` abierta. Tarda ~30-40 min con varias pausas de confirmación.

---

## Objetivo del bloque

Generar los 13 Eloquent Models necesarios para las 14 tablas legacy (omitimos `session` que no se toca), con:

- `$table`, `$primaryKey`, `$timestamps=false`, `$fillable`, `$hidden`, `$casts` correctos según ARCHITECTURE.md §5.1, ADR-0007 (Modulo constants/scopes), ADR-0008 (auth fields).
- Relaciones HasMany/BelongsTo según el ER §5.2 (asignación → averia → piv, no asignación → piv directo).
- **Custom Cast `Latin1String`** que revierte el doble-encoding latin1↔utf8 en lectura/escritura.
- **Migrations test-only** en `database/migrations/legacy_test/` que crean las tablas legacy en SQLite memoria para tests, sin tocar producción.
- **Smoke tests** por modelo + tests del Cast + tests de scopes/constants críticos (ADR-0007 Modulo).
- CI verde con Pint + Pest sobre PHP 8.2 y 8.3.

**Cero cambios en producción.** Solo código local que se valida con SQLite memory.

**Definition of Done:**
1. 13 archivos `app/Models/<Modelo>.php` creados.
2. 1 archivo `app/Casts/Latin1String.php` creado.
3. 1 migration `database/migrations/legacy_test/...create_legacy_tables.php` que crea las 14 tablas con schema correcto.
4. 1 archivo `tests/Pest.php` actualizado para cargar las legacy_test migrations en tests.
5. 13 smoke tests (uno por modelo) + 1 test del Cast + tests de Modulo scopes/constants.
6. `./vendor/bin/pest` verde local.
7. `./vendor/bin/pint --test` verde local.
8. `npm run build` verde local.
9. Branch `bloque-03-eloquent-models` pusheada, PR creado, CI verde sobre los 3 jobs.

---

## Riesgos y mitigaciones

- **PK convention legacy: `<tabla>_id` para 13 modelos, `user_id` para U1**. ADR-0008 cubre. Cada model declara `$primaryKey` explícito.
- **Charset latin1 mojibake**: el Cast `Latin1String` lo maneja con `mb_convert_encoding`. Test de round-trip lo valida.
- **Tablas legacy NO se crean por migrations en producción**: las migrations van en directorio separado `legacy_test/` que solo se carga en entorno test. En prod, esas tablas YA existen (verificadas en Bloque 02).
- **Relaciones `Piv ↔ Operador (×3)` y `Asignacion → Piv via Averia`**: definidas según ER §5.2. Para `Piv ↔ Operador` hay 3 BelongsTo separados (`operadorPrincipal`, `operadorSecundario`, `operadorTerciario`). Para `Asignacion → Piv` un accessor `piv` que delega a `$this->averia->piv`.
- **Datos RGPD en Tecnico**: `clave` está en `$hidden`. Tests verifican que `$tecnico->toArray()` NO expone `clave`. Otros campos sensibles (`dni`, `n_seguridad_social`, `ccc`, `telefono`, `direccion`, `email`, `carnet_conducir`) NO están en $hidden por defecto pero el `TecnicoExportTransformer` (Bloque 10) los filtrará al exportar. Aquí en Bloque 03 son accesibles vía Eloquent porque admin SÍ los necesita ver.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero estos archivos en orden:
- .github/copilot-instructions.md (convenciones generales + tests obligatorios)
- CLAUDE.md (división de trabajo)
- ARCHITECTURE.md §5 entera (modelo de dominio con schema verificado)
- docs/decisions/0006-correctivo-schema-strategy.md
- docs/decisions/0007-piv-municipio-validation.md
- docs/decisions/0008-auth-field-correction.md
- docs/prompts/03-eloquent-models.md (este archivo, secciones de objetivo y riesgos)

Tu tarea: implementar el Bloque 03 — generar 13 Eloquent Models para tablas legacy + Cast custom Latin1String + migrations test-only + smoke tests. Cero cambios en producción. Trabajo local + PR.

Sigue las fases en orden. PARA y AVISA tras cada fase para que pueda revisar antes de seguir.

## FASE 0 — Pre-flight + branch

Verifica estado:

```bash
pwd                                    # /Users/winfin/Documents/winfin-piv
git branch --show-current              # main
git rev-parse HEAD                     # debe ser 569a0f3 (o más adelante)
git status --short                     # vacío
php artisan --version | head -1        # Laravel 12
./vendor/bin/pest --version            # Pest 3.x
```

Si algo no encaja, AVISA y para.

Crea rama feature:

```bash
git checkout -b bloque-03-eloquent-models
git branch --show-current              # bloque-03-eloquent-models
```

PARA y avisa al usuario: "Branch creada, listo para Fase 1 (Cast Latin1String). ¿Procedo?"

## FASE 1 — Custom Cast Latin1String

Crea `app/Casts/Latin1String.php`:

```php
<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast que revierte el doble-encoding latin1->utf8 en columnas legacy.
 *
 * Las tablas legacy de Winfin PIV están en charset latin1, pero la app vieja
 * escribió bytes UTF-8 directamente en ellas. Cuando Laravel lee a través de
 * la conexión utf8mb4 default, MySQL aplica conversión latin1->utf8 sobre los
 * bytes UTF-8 ya almacenados, produciendo doble-encoding (mojibake clásico:
 * "Alcalá" -> "AlcalÃ¡").
 *
 * Este Cast revierte el efecto en lectura y lo replica en escritura para
 * mantener consistencia con datos legacy preexistentes.
 *
 * Verificado contra producción 2026-04-30 (Bloque 02): modulo.nombre devuelve
 * "AlcalÃ¡ de Henares" sin Cast, "Alcalá de Henares" con Cast.
 */
class Latin1String implements CastsAttributes
{
    /**
     * Lectura: convierte el string mojibake recibido de MySQL a UTF-8 correcto.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            return $value;
        }

        // Estrategia: interpretar el string utf8 recibido como si fuera latin1
        // y reconvertir a utf8. Esto deshace la latin1->utf8 conversión que
        // MySQL aplicó al leer.
        $converted = mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');

        return $converted !== false ? $converted : $value;
    }

    /**
     * Escritura: prepara el string utf8 para que MySQL utf8mb4->latin1 lo
     * almacene como bytes utf8 (consistente con cómo escribía la app vieja).
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            return $value;
        }

        // Inverso del get(): expandir cada byte como si fuera latin1 a utf8.
        $converted = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');

        return $converted !== false ? $converted : $value;
    }
}
```

Crea `tests/Unit/Casts/Latin1StringTest.php`:

```php
<?php

declare(strict_types=1);

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Model;

beforeEach(function () {
    $this->cast = new Latin1String();
    $this->model = new class extends Model {};
});

test('get reverts mojibake from latin1 column read via utf8mb4', function () {
    expect($this->cast->get($this->model, 'col', 'AlcalÃ¡ de Henares', []))
        ->toBe('Alcalá de Henares');
    expect($this->cast->get($this->model, 'col', 'AlcorcÃ³n', []))
        ->toBe('Alcorcón');
});

test('get is idempotent for plain ASCII', function () {
    expect($this->cast->get($this->model, 'col', 'Madrid', []))
        ->toBe('Madrid');
});

test('get returns null for null', function () {
    expect($this->cast->get($this->model, 'col', null, []))
        ->toBeNull();
});

test('set produces mojibake bytes that round-trip through get', function () {
    $original = 'Alcalá de Henares';
    $stored = $this->cast->set($this->model, 'col', $original, []);
    // El round-trip set+get debe devolver el original (consistencia
    // con cómo lee MySQL el dato post-escritura).
    expect($this->cast->get($this->model, 'col', $stored, []))
        ->toBe($original);
});

test('set returns null for null', function () {
    expect($this->cast->set($this->model, 'col', null, []))
        ->toBeNull();
});

test('round-trip handles common Spanish chars: ñ á é í ó ú', function () {
    foreach (['España', 'Móstoles', 'Cádiz', 'León', 'Logroño'] as $original) {
        $stored = $this->cast->set($this->model, 'col', $original, []);
        $read = $this->cast->get($this->model, 'col', $stored, []);
        expect($read)->toBe($original);
    }
});
```

Corre los tests:

```bash
./vendor/bin/pest tests/Unit/Casts/Latin1StringTest.php --colors=never
```

Deben pasar los 6 tests. Si alguno falla, AVISA con el output.

PARA y avisa: "Fase 1 completa: Latin1String + tests. ¿Procedo a Fase 2 (test infra)?"

## FASE 2 — Test infrastructure (legacy schema en SQLite)

Crea directorio `database/migrations/legacy_test/` (separado del directorio de prod migrations):

```bash
mkdir -p database/migrations/legacy_test
```

Crea `database/migrations/legacy_test/2026_04_30_000000_create_legacy_tables.php` con el schema completo de las 14 tablas. **Importante**: este archivo NUNCA se carga en prod (verificado más abajo en TestCase config). Solo en tests con SQLite memory.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea las 14 tablas legacy en BD de tests (SQLite memory).
 *
 * Schema mirror de producción MySQL latin1, simplificado para SQLite:
 * - `int` en MySQL -> `integer` en SQLite.
 * - `varchar(N)` -> `string` con length.
 * - `tinyint` -> `boolean` o `integer` según uso semántico (default int para preservar valores).
 * - `timestamp default CURRENT_TIMESTAMP` -> `timestamp` con default Carbon.
 *
 * Esta migration NO se ejecuta en producción. Solo en tests vía
 * loadMigrationsFrom('database/migrations/legacy_test') en tests/Pest.php.
 *
 * Schema verificado contra INFORMATION_SCHEMA prod 2026-04-30 (Bloque 02).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- piv (575 filas en prod) ----------
        Schema::create('piv', function (Blueprint $t) {
            $t->integer('piv_id')->primary();
            $t->string('parada_cod', 255)->nullable();
            $t->string('cc_cod', 255)->nullable();
            $t->date('fecha_instalacion')->nullable();
            $t->string('n_serie_piv', 255)->nullable();
            $t->string('n_serie_sim', 255)->nullable();
            $t->string('n_serie_mgp', 255)->nullable();
            $t->string('tipo_piv', 255)->nullable();
            $t->string('tipo_marquesina', 255)->nullable();
            $t->string('tipo_alimentacion', 255)->nullable();
            $t->integer('industria_id')->nullable();
            $t->integer('concesionaria_id')->nullable();
            $t->string('direccion', 255)->nullable();
            $t->string('municipio', 255)->nullable();
            $t->tinyInteger('status')->nullable()->default(1);
            $t->integer('operador_id')->nullable();
            $t->integer('operador_id_2')->nullable();
            $t->integer('operador_id_3')->nullable();
            $t->string('prevision', 500)->nullable();
            $t->string('observaciones', 500)->nullable();
            $t->string('mantenimiento', 45)->nullable();
            $t->tinyInteger('status2')->nullable()->default(1);
        });

        // ---------- averia ----------
        Schema::create('averia', function (Blueprint $t) {
            $t->unsignedInteger('averia_id')->primary();
            $t->integer('operador_id')->nullable();
            $t->integer('piv_id')->nullable();
            $t->string('notas', 500)->nullable();
            $t->timestamp('fecha')->useCurrent()->nullable();
            $t->tinyInteger('status')->nullable();
            $t->integer('tecnico_id')->nullable();
        });

        // ---------- asignacion ----------
        Schema::create('asignacion', function (Blueprint $t) {
            $t->integer('asignacion_id')->primary();
            $t->integer('tecnico_id')->nullable();
            $t->date('fecha')->nullable();
            $t->integer('hora_inicial')->nullable();
            $t->integer('hora_final')->nullable();
            $t->tinyInteger('tipo')->nullable();   // 1 correctivo, 2 revisión
            $t->unsignedInteger('averia_id')->nullable();
            $t->tinyInteger('status')->nullable();
        });

        // ---------- correctivo ----------
        Schema::create('correctivo', function (Blueprint $t) {
            $t->integer('correctivo_id')->primary();
            $t->integer('tecnico_id')->nullable();
            $t->integer('asignacion_id')->nullable();
            $t->string('tiempo', 45)->nullable();
            $t->tinyInteger('contrato')->nullable();
            $t->tinyInteger('facturar_horas')->nullable();
            $t->tinyInteger('facturar_desplazamiento')->nullable();
            $t->tinyInteger('facturar_recambios')->nullable();
            $t->string('recambios', 255)->nullable();
            $t->string('diagnostico', 255)->nullable();
            $t->string('estado_final', 100)->nullable();
        });

        // ---------- revision ----------
        Schema::create('revision', function (Blueprint $t) {
            $t->unsignedInteger('revision_id')->primary();
            $t->integer('tecnico_id')->nullable();
            $t->integer('asignacion_id')->nullable();
            $t->string('fecha', 100)->nullable();
            $t->string('ruta', 100)->nullable();
            $t->string('aspecto', 100)->nullable();
            $t->string('funcionamiento', 100)->nullable();
            $t->string('actuacion', 100)->nullable();
            $t->string('audio', 100)->nullable();
            $t->string('lineas', 100)->nullable();
            $t->string('fecha_hora', 100)->nullable();
            $t->string('precision_paso', 100)->nullable();
            $t->string('notas', 100)->nullable();
        });

        // ---------- tecnico (RGPD sensitive) ----------
        Schema::create('tecnico', function (Blueprint $t) {
            $t->integer('tecnico_id')->primary();
            $t->string('usuario', 200)->nullable();
            $t->string('clave', 200)->nullable();      // SHA1 legacy
            $t->string('email', 200)->nullable();
            $t->string('nombre_completo', 200)->nullable();
            $t->string('dni', 200)->nullable();
            $t->string('carnet_conducir', 200)->nullable();
            $t->string('direccion', 200)->nullable();
            $t->string('ccc', 200)->nullable();
            $t->string('n_seguridad_social', 200)->nullable();
            $t->string('telefono', 200)->nullable();
            $t->tinyInteger('status')->nullable()->default(1);
        });

        // ---------- operador ----------
        Schema::create('operador', function (Blueprint $t) {
            $t->integer('operador_id')->primary();
            $t->string('usuario', 255)->nullable();
            $t->string('clave', 255)->nullable();      // SHA1 legacy
            $t->string('email', 255)->nullable();
            $t->string('domicilio', 255)->nullable();
            $t->string('lineas', 255)->nullable();
            $t->string('responsable', 255)->nullable();
            $t->string('razon_social', 255)->nullable();
            $t->string('cif', 255)->nullable();
            $t->tinyInteger('status')->nullable()->default(1);
        });

        // ---------- modulo (catálogo polimórfico) ----------
        Schema::create('modulo', function (Blueprint $t) {
            $t->integer('modulo_id')->primary();
            $t->string('nombre', 255)->nullable();
            $t->integer('tipo')->nullable();
        });

        // ---------- piv_imagen ----------
        Schema::create('piv_imagen', function (Blueprint $t) {
            $t->integer('piv_imagen_id')->primary();
            $t->integer('piv_id')->nullable();
            $t->string('url', 255)->nullable();
            $t->integer('posicion')->nullable();
        });

        // ---------- instalador_piv ----------
        Schema::create('instalador_piv', function (Blueprint $t) {
            $t->integer('instalador_piv_id')->primary();
            $t->integer('piv_id')->nullable();
            $t->integer('instalador_id')->nullable();
        });

        // ---------- desinstalado_piv ----------
        Schema::create('desinstalado_piv', function (Blueprint $t) {
            $t->unsignedInteger('desinstalado_piv_id')->primary();
            $t->integer('piv_id')->nullable();
            $t->string('observaciones', 500)->nullable();
            $t->integer('pos')->nullable();
        });

        // ---------- reinstalado_piv ----------
        Schema::create('reinstalado_piv', function (Blueprint $t) {
            $t->unsignedInteger('reinstalado_piv_id')->primary();
            $t->integer('piv_id')->nullable();
            $t->string('observaciones', 500)->nullable();
            $t->integer('pos')->nullable();
        });

        // ---------- u1 (admins, PK = user_id excepción ADR-0008) ----------
        Schema::create('u1', function (Blueprint $t) {
            $t->integer('user_id')->primary();
            $t->string('username', 255)->nullable();
            $t->string('password', 255)->nullable();   // SHA1 legacy
            $t->string('email', 255)->nullable();
        });

        // ---------- session (legacy PHP, no se usa) ----------
        Schema::create('session', function (Blueprint $t) {
            $t->string('session_id', 255)->primary();
            $t->string('user_id', 255)->nullable();
            $t->integer('rol')->nullable();
        });
    }

    public function down(): void
    {
        // Drop en orden inverso para respetar FKs lógicas.
        foreach (['session', 'u1', 'reinstalado_piv', 'desinstalado_piv', 'instalador_piv', 'piv_imagen', 'modulo', 'operador', 'tecnico', 'revision', 'correctivo', 'asignacion', 'averia', 'piv'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
```

Edita `tests/Pest.php` para cargar las legacy_test migrations en tests:

Lee primero el contenido actual de `tests/Pest.php` y muéstramelo. Si tiene la sección `uses()` configurada, añade el método `defineDatabaseMigrations()` o equivalente. Si no, propón el cambio mínimo necesario para que `RefreshDatabase` cargue tanto las migrations default como las de `legacy_test/`.

La forma estándar:

```php
<?php

uses(\Tests\TestCase::class)->in('Feature', 'Unit');

// ... resto del archivo
```

Y en `tests/TestCase.php` (o creando uno si no existe):

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Carga las migraciones legacy_test en cada test que use RefreshDatabase.
        $this->loadMigrationsFrom(database_path('migrations/legacy_test'));
    }
}
```

Verifica que la migration legacy_test corre en SQLite test:

```bash
./vendor/bin/pest --filter='it can run legacy migrations' --colors=never 2>&1 | tail -10
```

Crea un test smoke `tests/Feature/LegacyTablesMigrationTest.php`:

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the 14 legacy tables in SQLite test DB', function () {
    foreach (['piv','averia','asignacion','correctivo','revision','tecnico','operador','modulo','piv_imagen','instalador_piv','desinstalado_piv','reinstalado_piv','u1','session'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Tabla {$table} no existe en BD test");
    }
});

it('piv has correct primary key', function () {
    expect(Schema::hasColumn('piv', 'piv_id'))->toBeTrue();
});

it('u1 has user_id as primary key (ADR-0008 excepción)', function () {
    expect(Schema::hasColumn('u1', 'user_id'))->toBeTrue();
    expect(Schema::hasColumn('u1', 'u1_id'))->toBeFalse();
});

it('tecnico password column is clave (ADR-0008)', function () {
    expect(Schema::hasColumn('tecnico', 'clave'))->toBeTrue();
    expect(Schema::hasColumn('tecnico', 'password'))->toBeFalse();
});

it('operador password column is clave (ADR-0008)', function () {
    expect(Schema::hasColumn('operador', 'clave'))->toBeTrue();
});

it('correctivo lacks accion/imagen (ADR-0006)', function () {
    expect(Schema::hasColumn('correctivo', 'diagnostico'))->toBeTrue();
    expect(Schema::hasColumn('correctivo', 'recambios'))->toBeTrue();
    expect(Schema::hasColumn('correctivo', 'accion'))->toBeFalse();
    expect(Schema::hasColumn('correctivo', 'imagen'))->toBeFalse();
});
```

Corre los tests:

```bash
./vendor/bin/pest tests/Feature/LegacyTablesMigrationTest.php --colors=never 2>&1 | tail -15
```

Los 6 tests deben pasar. Si fallan, AVISA.

PARA y avisa: "Fase 2 completa: legacy schema en test DB + verificación de constraints clave (ADRs 0006/0008). ¿Procedo a Fase 3 (catalog/leaf models)?"

## FASE 3 — Catalog models: Modulo + U1

Crea `app/Models/Modulo.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo polimórfico legacy.
 *
 * Una sola tabla con 12 tipos distintos. Cada `tipo` representa una categoría
 * reutilizable referenciada por columnas de `piv`, `revision`, etc.
 *
 * Ver ADR-0007 para detalles. Schema verificado 2026-04-30.
 */
class Modulo extends Model
{
    /** Tipos descubiertos en INFORMATION_SCHEMA prod (Bloque 02). */
    public const TIPO_INDUSTRIA            = 1;
    public const TIPO_PANTALLA             = 2;
    public const TIPO_MARQUESINA           = 3;
    public const TIPO_ALIMENTACION         = 4;
    public const TIPO_MUNICIPIO            = 5;
    public const TIPO_ESTADO_PIV           = 6;
    public const TIPO_CHECK_ASPECTO        = 9;
    public const TIPO_CHECK_FUNCIONAMIENTO = 10;
    public const TIPO_CHECK_ACTUACION      = 11;
    public const TIPO_CHECK_AUDIO          = 12;
    public const TIPO_CHECK_FECHA_HORA     = 13;
    public const TIPO_CHECK_PRECISION_PASO = 14;

    protected $table = 'modulo';
    protected $primaryKey = 'modulo_id';
    public $timestamps = false;

    protected $fillable = ['nombre', 'tipo'];

    protected $casts = [
        'tipo' => 'integer',
        'nombre' => Latin1String::class,
    ];

    public function scopeMunicipios(Builder $q): Builder
    {
        return $q->where('tipo', self::TIPO_MUNICIPIO);
    }

    public function scopeIndustrias(Builder $q): Builder
    {
        return $q->where('tipo', self::TIPO_INDUSTRIA);
    }

    public function scopeChecks(Builder $q): Builder
    {
        return $q->whereIn('tipo', [
            self::TIPO_CHECK_ASPECTO,
            self::TIPO_CHECK_FUNCIONAMIENTO,
            self::TIPO_CHECK_ACTUACION,
            self::TIPO_CHECK_AUDIO,
            self::TIPO_CHECK_FECHA_HORA,
            self::TIPO_CHECK_PRECISION_PASO,
        ]);
    }

    public function scopePantallas(Builder $q): Builder
    {
        return $q->where('tipo', self::TIPO_PANTALLA);
    }

    public function scopeMarquesinas(Builder $q): Builder
    {
        return $q->where('tipo', self::TIPO_MARQUESINA);
    }

    public function scopeAlimentaciones(Builder $q): Builder
    {
        return $q->where('tipo', self::TIPO_ALIMENTACION);
    }
}
```

Crea `app/Models/U1.php` (excepción ADR-0008 para PK):

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabla legacy `u1` para administradores.
 *
 * Excepciones a las convenciones del proyecto (ver ADR-0008):
 * - PK es `user_id`, NO `u1_id`.
 * - Columna password se llama `password` (en `tecnico` y `operador` se llama `clave`).
 *
 * Schema verificado 2026-04-30: 1 fila en prod (1 admin).
 */
class U1 extends Model
{
    protected $table = 'u1';
    protected $primaryKey = 'user_id';
    public $timestamps = false;

    protected $fillable = ['username', 'email', 'password'];

    protected $hidden = ['password'];
}
```

Crea factories:

`database/factories/ModuloFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Modulo;
use Illuminate\Database\Eloquent\Factories\Factory;

class ModuloFactory extends Factory
{
    protected $model = Modulo::class;

    public function definition(): array
    {
        return [
            'modulo_id' => $this->faker->unique()->randomNumber(),
            'nombre' => $this->faker->word(),
            'tipo' => Modulo::TIPO_MUNICIPIO,
        ];
    }

    public function municipio(string $nombre = null): static
    {
        return $this->state(['tipo' => Modulo::TIPO_MUNICIPIO, 'nombre' => $nombre ?? $this->faker->city()]);
    }

    public function industria(): static
    {
        return $this->state(['tipo' => Modulo::TIPO_INDUSTRIA]);
    }
}
```

`database/factories/U1Factory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\U1;
use Illuminate\Database\Eloquent\Factories\Factory;

class U1Factory extends Factory
{
    protected $model = U1::class;

    public function definition(): array
    {
        return [
            'user_id' => $this->faker->unique()->randomNumber(),
            'username' => $this->faker->userName(),
            'email' => $this->faker->safeEmail(),
            'password' => sha1('password'),
        ];
    }
}
```

Añade el trait `HasFactory` a `Modulo` y `U1` (modifica los archivos arriba para incluirlo). Ejemplo:

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Modulo extends Model
{
    use HasFactory;
    // ...
}
```

Crea smoke tests:

`tests/Feature/Models/ModuloTest.php`:

```php
<?php

use App\Models\Modulo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('loads a modulo row with its tipo and nombre', function () {
    $m = Modulo::factory()->create(['nombre' => 'Madrid', 'tipo' => 5]);
    $found = Modulo::find($m->modulo_id);
    expect($found)->not->toBeNull();
    expect($found->nombre)->toBe('Madrid');
    expect($found->tipo)->toBe(5);
});

it('scopeMunicipios filters by tipo=5', function () {
    Modulo::factory()->create(['tipo' => Modulo::TIPO_INDUSTRIA, 'modulo_id' => 1]);
    Modulo::factory()->create(['tipo' => Modulo::TIPO_MUNICIPIO, 'modulo_id' => 2]);
    Modulo::factory()->create(['tipo' => Modulo::TIPO_MUNICIPIO, 'modulo_id' => 3]);
    expect(Modulo::municipios()->count())->toBe(2);
});

it('scopeChecks filters by tipos 9-14', function () {
    Modulo::factory()->create(['tipo' => Modulo::TIPO_INDUSTRIA, 'modulo_id' => 1]);
    Modulo::factory()->create(['tipo' => Modulo::TIPO_CHECK_ASPECTO, 'modulo_id' => 9]);
    Modulo::factory()->create(['tipo' => Modulo::TIPO_CHECK_AUDIO, 'modulo_id' => 12]);
    expect(Modulo::checks()->count())->toBe(2);
});

it('Latin1String cast applied on nombre roundtrips Spanish chars', function () {
    $m = Modulo::factory()->create(['nombre' => 'Alcalá de Henares']);
    $m->refresh();
    expect($m->nombre)->toBe('Alcalá de Henares');
});

it('exposes type constants matching legacy values', function () {
    expect(Modulo::TIPO_MUNICIPIO)->toBe(5);
    expect(Modulo::TIPO_INDUSTRIA)->toBe(1);
    expect(Modulo::TIPO_CHECK_ASPECTO)->toBe(9);
});
```

`tests/Feature/Models/U1Test.php`:

```php
<?php

use App\Models\U1;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses user_id as primary key (ADR-0008)', function () {
    $u = U1::factory()->create(['user_id' => 1, 'username' => 'admin']);
    expect(U1::find(1))->not->toBeNull();
    expect(U1::find(1)->username)->toBe('admin');
});

it('hides password by default', function () {
    $u = U1::factory()->create();
    $array = $u->toArray();
    expect($array)->not->toHaveKey('password');
});
```

Corre los tests:

```bash
./vendor/bin/pest tests/Feature/Models/ModuloTest.php tests/Feature/Models/U1Test.php --colors=never 2>&1 | tail -15
```

Deben pasar todos. PARA y avisa: "Fase 3 completa: Modulo (con scopes y constantes ADR-0007) + U1 (PK user_id ADR-0008). ¿Procedo a Fase 4 (entity models)?"

## FASE 4 — Entity models: Tecnico + Operador

Crea `app/Models/Tecnico.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Técnico de campo. 65 filas en prod, 3 activas.
 *
 * Datos RGPD sensibles (regla #3): dni, n_seguridad_social, ccc, telefono,
 * direccion, email, carnet_conducir. NUNCA exportar al cliente. Ver
 * TecnicoExportTransformer (Bloque 10) para filtrado en exports.
 *
 * Password legacy se llama `clave` (NO `password`) — ver ADR-0008. Está en
 * $hidden para que no se serialice por accidente. La validación SHA1 la
 * hace LegacyHashGuard (Bloque 06) leyendo `clave` directamente vía DB::table.
 */
class Tecnico extends Model
{
    use HasFactory;

    protected $table = 'tecnico';
    protected $primaryKey = 'tecnico_id';
    public $timestamps = false;

    protected $fillable = [
        'usuario', 'clave', 'email', 'nombre_completo',
        'dni', 'carnet_conducir', 'direccion', 'ccc',
        'n_seguridad_social', 'telefono', 'status',
    ];

    protected $hidden = ['clave'];

    protected $casts = [
        'status' => 'integer',
        'nombre_completo' => Latin1String::class,
        'direccion' => Latin1String::class,
    ];

    public function asignaciones(): HasMany
    {
        return $this->hasMany(Asignacion::class, 'tecnico_id', 'tecnico_id');
    }

    public function averias(): HasMany
    {
        return $this->hasMany(Averia::class, 'tecnico_id', 'tecnico_id');
    }
}
```

Crea `app/Models/Operador.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Operador (cliente final). 41 filas en prod.
 *
 * Password legacy se llama `clave` (ADR-0008). $hidden para evitar leak
 * accidental en serialización JSON. La auth la hace LegacyHashGuard.
 *
 * Un operador puede ser principal, secundario o terciario en un PIV
 * (columnas piv.operador_id, _2, _3). Las relaciones aquí son las de
 * "operador principal"; las queries de scope a paneles del operador
 * (ver Bloque 12) deben hacer WHERE operador_id IN (op_id, _2, _3).
 */
class Operador extends Model
{
    use HasFactory;

    protected $table = 'operador';
    protected $primaryKey = 'operador_id';
    public $timestamps = false;

    protected $fillable = [
        'usuario', 'clave', 'email', 'domicilio', 'lineas',
        'responsable', 'razon_social', 'cif', 'status',
    ];

    protected $hidden = ['clave'];

    protected $casts = [
        'status' => 'integer',
        'razon_social' => Latin1String::class,
        'domicilio' => Latin1String::class,
        'responsable' => Latin1String::class,
    ];

    /**
     * Paneles donde este operador es el principal.
     * Para todos los paneles del operador (incluyendo _2 y _3), ver el scope
     * Piv::scopeForOperador().
     */
    public function paneles(): HasMany
    {
        return $this->hasMany(Piv::class, 'operador_id', 'operador_id');
    }

    public function averias(): HasMany
    {
        return $this->hasMany(Averia::class, 'operador_id', 'operador_id');
    }
}
```

Crea factories `TecnicoFactory.php` y `OperadorFactory.php` siguiendo el patrón ya establecido.

Crea smoke tests para ambos:

`tests/Feature/Models/TecnicoTest.php`:

```php
<?php

use App\Models\Tecnico;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses tecnico_id as primary key', function () {
    $t = Tecnico::factory()->create(['tecnico_id' => 5]);
    expect(Tecnico::find(5))->not->toBeNull();
});

it('hides clave from serialization (ADR-0008 + regla #3 RGPD)', function () {
    $t = Tecnico::factory()->create(['clave' => 'should-not-leak']);
    expect($t->toArray())->not->toHaveKey('clave');
    expect($t->toJson())->not->toContain('should-not-leak');
});

it('Latin1String cast applied on nombre_completo and direccion', function () {
    $t = Tecnico::factory()->create([
        'nombre_completo' => 'Rubén Martín',
        'direccion' => 'Calle Mayor 1, Móstoles',
    ]);
    $t->refresh();
    expect($t->nombre_completo)->toBe('Rubén Martín');
    expect($t->direccion)->toBe('Calle Mayor 1, Móstoles');
});
```

`tests/Feature/Models/OperadorTest.php`:

```php
<?php

use App\Models\Operador;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses operador_id as primary key', function () {
    $o = Operador::factory()->create(['operador_id' => 7]);
    expect(Operador::find(7))->not->toBeNull();
});

it('hides clave from serialization', function () {
    $o = Operador::factory()->create(['clave' => 'should-not-leak']);
    expect($o->toArray())->not->toHaveKey('clave');
});

it('Latin1String cast on razon_social and domicilio', function () {
    $o = Operador::factory()->create([
        'razon_social' => 'EMT Móstoles S.A.',
        'domicilio' => 'Calle del Sol, Cádiz',
    ]);
    $o->refresh();
    expect($o->razon_social)->toBe('EMT Móstoles S.A.');
});
```

Corre los tests, confirma verde, y PARA: "Fase 4 completa: Tecnico + Operador con $hidden=clave (ADR-0008) + casts Latin1. ¿Procedo a Fase 5 (Piv core)?"

## FASE 5 — Core: Piv

Crea `app/Models/Piv.php` con TODAS las 22 columnas + relaciones según ER §5.2:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Panel PIV físico instalado en marquesina. 575 filas en prod.
 *
 * Schema verificado contra INFORMATION_SCHEMA 2026-04-30. NO tiene lat/lng
 * (geocoding pendiente — Bloque 02f). Hasta 3 operadores responsables.
 * `municipio` es varchar con id de Modulo tipo=5 (ver ADR-0007).
 */
class Piv extends Model
{
    use HasFactory;

    protected $table = 'piv';
    protected $primaryKey = 'piv_id';
    public $timestamps = false;

    protected $fillable = [
        'parada_cod', 'cc_cod', 'fecha_instalacion',
        'n_serie_piv', 'n_serie_sim', 'n_serie_mgp',
        'tipo_piv', 'tipo_marquesina', 'tipo_alimentacion',
        'industria_id', 'concesionaria_id',
        'direccion', 'municipio',
        'status', 'operador_id', 'operador_id_2', 'operador_id_3',
        'prevision', 'observaciones', 'mantenimiento', 'status2',
    ];

    protected $casts = [
        'fecha_instalacion' => 'date',
        'industria_id' => 'integer',
        'concesionaria_id' => 'integer',
        'status' => 'integer',
        'status2' => 'integer',
        'operador_id' => 'integer',
        'operador_id_2' => 'integer',
        'operador_id_3' => 'integer',
        'direccion' => Latin1String::class,
        'observaciones' => Latin1String::class,
        'prevision' => Latin1String::class,
    ];

    public function operadorPrincipal(): BelongsTo
    {
        return $this->belongsTo(Operador::class, 'operador_id', 'operador_id');
    }

    public function operadorSecundario(): BelongsTo
    {
        return $this->belongsTo(Operador::class, 'operador_id_2', 'operador_id');
    }

    public function operadorTerciario(): BelongsTo
    {
        return $this->belongsTo(Operador::class, 'operador_id_3', 'operador_id');
    }

    public function industria(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'industria_id', 'modulo_id');
    }

    public function averias(): HasMany
    {
        return $this->hasMany(Averia::class, 'piv_id', 'piv_id');
    }

    public function imagenes(): HasMany
    {
        return $this->hasMany(PivImagen::class, 'piv_id', 'piv_id')->orderBy('posicion');
    }

    public function instalaciones(): HasMany
    {
        return $this->hasMany(InstaladorPiv::class, 'piv_id', 'piv_id');
    }

    public function desinstalaciones(): HasMany
    {
        return $this->hasMany(DesinstaladoPiv::class, 'piv_id', 'piv_id');
    }

    public function reinstalaciones(): HasMany
    {
        return $this->hasMany(ReinstaladoPiv::class, 'piv_id', 'piv_id');
    }

    /**
     * Scope: paneles donde el operador dado es principal, secundario o terciario.
     * Útil para Bloque 12 (PWA operador) y Bloque 07 (filtros admin).
     */
    public function scopeForOperador(Builder $q, int $operadorId): Builder
    {
        return $q->where(function ($w) use ($operadorId) {
            $w->where('operador_id', $operadorId)
                ->orWhere('operador_id_2', $operadorId)
                ->orWhere('operador_id_3', $operadorId);
        });
    }
}
```

Factory + smoke test:

`database/factories/PivFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Piv;
use Illuminate\Database\Eloquent\Factories\Factory;

class PivFactory extends Factory
{
    protected $model = Piv::class;

    public function definition(): array
    {
        return [
            'piv_id' => $this->faker->unique()->randomNumber(),
            'parada_cod' => $this->faker->bothify('PARADA-####'),
            'direccion' => $this->faker->streetAddress(),
            'municipio' => '0',
            'status' => 1,
        ];
    }
}
```

`tests/Feature/Models/PivTest.php`:

```php
<?php

use App\Models\Operador;
use App\Models\Piv;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses piv_id as primary key', function () {
    $p = Piv::factory()->create(['piv_id' => 100]);
    expect(Piv::find(100))->not->toBeNull();
});

it('belongsTo three operadores', function () {
    $a = Operador::factory()->create(['operador_id' => 1]);
    $b = Operador::factory()->create(['operador_id' => 2]);
    $c = Operador::factory()->create(['operador_id' => 3]);
    $p = Piv::factory()->create([
        'operador_id' => 1, 'operador_id_2' => 2, 'operador_id_3' => 3,
    ]);
    expect($p->operadorPrincipal->operador_id)->toBe(1);
    expect($p->operadorSecundario->operador_id)->toBe(2);
    expect($p->operadorTerciario->operador_id)->toBe(3);
});

it('scopeForOperador finds panels regardless of slot 1/2/3', function () {
    Operador::factory()->create(['operador_id' => 5]);
    Piv::factory()->create(['piv_id' => 1, 'operador_id' => 5]);
    Piv::factory()->create(['piv_id' => 2, 'operador_id_2' => 5]);
    Piv::factory()->create(['piv_id' => 3, 'operador_id_3' => 5]);
    Piv::factory()->create(['piv_id' => 4, 'operador_id' => 99]);
    expect(Piv::forOperador(5)->count())->toBe(3);
});

it('Latin1String cast on direccion roundtrips', function () {
    $p = Piv::factory()->create(['direccion' => 'Avenida de Móstoles, 142']);
    $p->refresh();
    expect($p->direccion)->toBe('Avenida de Móstoles, 142');
});
```

Corre tests, confirma verde. PARA: "Fase 5 completa: Piv con 3 operadores + scope forOperador + casts. ¿Procedo a Fase 6 (history models)?"

## FASE 6 — History models

Crea `app/Models/PivImagen.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PivImagen extends Model
{
    use HasFactory;

    protected $table = 'piv_imagen';
    protected $primaryKey = 'piv_imagen_id';
    public $timestamps = false;

    protected $fillable = ['piv_id', 'url', 'posicion'];

    protected $casts = [
        'piv_id' => 'integer',
        'posicion' => 'integer',
    ];

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }
}
```

Crea `app/Models/InstaladorPiv.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstaladorPiv extends Model
{
    use HasFactory;

    protected $table = 'instalador_piv';
    protected $primaryKey = 'instalador_piv_id';
    public $timestamps = false;

    protected $fillable = ['piv_id', 'instalador_id'];

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }

    public function instalador(): BelongsTo
    {
        return $this->belongsTo(U1::class, 'instalador_id', 'user_id');
    }
}
```

Crea `app/Models/DesinstaladoPiv.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesinstaladoPiv extends Model
{
    use HasFactory;

    protected $table = 'desinstalado_piv';
    protected $primaryKey = 'desinstalado_piv_id';
    public $timestamps = false;

    protected $fillable = ['piv_id', 'observaciones', 'pos'];

    protected $casts = [
        'observaciones' => Latin1String::class,
    ];

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }
}
```

Crea `app/Models/ReinstaladoPiv.php` (idéntico patrón a DesinstaladoPiv pero con su tabla).

Factories + smoke tests minimal (que el modelo carga, que la relación a `Piv` resuelve). Una sola línea de test cada uno es suficiente para Bloque 03.

Corre tests, verde, PARA: "Fase 6 completa: 4 history models. ¿Procedo a Fase 7 (transactional)?"

## FASE 7 — Transactional models

Crea `app/Models/Averia.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Averia extends Model
{
    use HasFactory;

    protected $table = 'averia';
    protected $primaryKey = 'averia_id';
    public $timestamps = false;

    protected $fillable = ['operador_id', 'piv_id', 'notas', 'fecha', 'status', 'tecnico_id'];

    protected $casts = [
        'fecha' => 'datetime',
        'piv_id' => 'integer',
        'operador_id' => 'integer',
        'tecnico_id' => 'integer',
        'status' => 'integer',
        'notas' => Latin1String::class,
    ];

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(Operador::class, 'operador_id', 'operador_id');
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(Tecnico::class, 'tecnico_id', 'tecnico_id');
    }

    public function asignacion(): HasOne
    {
        return $this->hasOne(Asignacion::class, 'averia_id', 'averia_id');
    }
}
```

Crea `app/Models/Asignacion.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Asignacion extends Model
{
    use HasFactory;

    public const TIPO_CORRECTIVO = 1;
    public const TIPO_REVISION   = 2;

    protected $table = 'asignacion';
    protected $primaryKey = 'asignacion_id';
    public $timestamps = false;

    protected $fillable = [
        'tecnico_id', 'fecha', 'hora_inicial', 'hora_final',
        'tipo', 'averia_id', 'status',
    ];

    protected $casts = [
        'fecha' => 'date',
        'hora_inicial' => 'integer',
        'hora_final' => 'integer',
        'tipo' => 'integer',
        'tecnico_id' => 'integer',
        'averia_id' => 'integer',
        'status' => 'integer',
    ];

    public function averia(): BelongsTo
    {
        return $this->belongsTo(Averia::class, 'averia_id', 'averia_id');
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(Tecnico::class, 'tecnico_id', 'tecnico_id');
    }

    public function correctivo(): HasOne
    {
        return $this->hasOne(Correctivo::class, 'asignacion_id', 'asignacion_id');
    }

    public function revision(): HasOne
    {
        return $this->hasOne(Revision::class, 'asignacion_id', 'asignacion_id');
    }

    /**
     * El PIV de esta asignación (vía averia.piv_id).
     * Asignacion no tiene piv_id directo — schema legacy verificado 2026-04-30.
     */
    public function getPivAttribute(): ?Piv
    {
        return $this->averia?->piv;
    }
}
```

Crea `app/Models/Correctivo.php` (campos según ADR-0006):

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Correctivo extends Model
{
    use HasFactory;

    protected $table = 'correctivo';
    protected $primaryKey = 'correctivo_id';
    public $timestamps = false;

    protected $fillable = [
        'tecnico_id', 'asignacion_id',
        'tiempo', 'contrato',
        'facturar_horas', 'facturar_desplazamiento', 'facturar_recambios',
        'recambios', 'diagnostico', 'estado_final',
    ];

    protected $casts = [
        'contrato' => 'boolean',
        'facturar_horas' => 'boolean',
        'facturar_desplazamiento' => 'boolean',
        'facturar_recambios' => 'boolean',
        'tecnico_id' => 'integer',
        'asignacion_id' => 'integer',
        'recambios' => Latin1String::class,
        'diagnostico' => Latin1String::class,
        'estado_final' => Latin1String::class,
    ];

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(Asignacion::class, 'asignacion_id', 'asignacion_id');
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(Tecnico::class, 'tecnico_id', 'tecnico_id');
    }
}
```

Crea `app/Models/Revision.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Revision extends Model
{
    use HasFactory;

    protected $table = 'revision';
    protected $primaryKey = 'revision_id';
    public $timestamps = false;

    protected $fillable = [
        'tecnico_id', 'asignacion_id', 'fecha', 'ruta',
        'aspecto', 'funcionamiento', 'actuacion', 'audio',
        'lineas', 'fecha_hora', 'precision_paso', 'notas',
    ];

    protected $casts = [
        'tecnico_id' => 'integer',
        'asignacion_id' => 'integer',
        'notas' => Latin1String::class,
    ];

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(Asignacion::class, 'asignacion_id', 'asignacion_id');
    }

    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(Tecnico::class, 'tecnico_id', 'tecnico_id');
    }
}
```

Factories minimal + smoke tests minimal (modelo carga, relaciones resuelven). Para Asignacion incluye un test específico:

```php
it('piv accessor walks through averia.piv', function () {
    $piv = Piv::factory()->create(['piv_id' => 100]);
    $averia = Averia::factory()->create(['averia_id' => 1, 'piv_id' => 100]);
    $asignacion = Asignacion::factory()->create(['asignacion_id' => 1, 'averia_id' => 1]);
    expect($asignacion->piv->piv_id)->toBe(100);
});
```

Y para Asignacion las constantes:

```php
it('exposes TIPO_CORRECTIVO and TIPO_REVISION constants', function () {
    expect(Asignacion::TIPO_CORRECTIVO)->toBe(1);
    expect(Asignacion::TIPO_REVISION)->toBe(2);
});
```

Corre tests, PARA: "Fase 7 completa: Averia + Asignacion + Correctivo + Revision con field mapping de ADR-0006. ¿Procedo a Fase 8 (verificación final)?"

## FASE 8 — Verificación final + pint + pest completo

Corre la suite completa:

```bash
./vendor/bin/pint --test 2>&1 | tail -5
./vendor/bin/pest --colors=never --compact 2>&1 | tail -15
npm run build 2>&1 | tail -5
```

Si Pint reporta cosas, corre `./vendor/bin/pint` (sin --test) y commitea como style. Si Pest tiene fallos, AVISA. Si npm build falla, AVISA.

Espera todo verde. PARA: "Fase 8 completa: tests verdes localmente. ¿Procedo a Fase 9 (commits + PR)?"

## FASE 9 — Commits granulares + push + PR

Crea commits separados por fase, conventional commits:

```bash
git status
# Stage por fase y commit. Mensajes inglés ≤72 chars subject + body en español.
```

Estructura sugerida (7-9 commits, ajusta según haya quedado). **El primer commit es siempre el archivo del prompt** (consistente con Bloques 01b/02):

1. `docs: add Bloque 03 prompt (Eloquent models for legacy tables)` ← incluye SOLO `docs/prompts/03-eloquent-models.md`
2. `feat(casts): add Latin1String for legacy mojibake roundtrip`
3. `test: add legacy schema migrations and base test infra`
4. `feat(models): add Modulo with type constants and scopes (ADR-0007)`
5. `feat(models): add U1 with user_id PK (ADR-0008)`
6. `feat(models): add Tecnico and Operador with hidden=clave (ADR-0008)`
7. `feat(models): add Piv core with three operadores and forOperador scope`
8. `feat(models): add piv_imagen and 3 instalacion history models`
9. `feat(models): add Averia, Asignacion, Correctivo, Revision`

Para cada commit:

```bash
git add <archivos específicos>
git status            # verificar SOLO los archivos esperados
git -c user.email="copilot@winfin.local" -c user.name="Winfin Copilot" \
    commit -m "feat(...): ..." -m "Body explicativo en español..."
```

NO uses `git add .` o `git add -A`. Solo los archivos del commit en cuestión.

Antes de pushear, smoke test final:

```bash
./vendor/bin/pest --colors=never --compact
./vendor/bin/pint --test
npm run build
```

Verde los 3. Push:

```bash
git push -u origin bloque-03-eloquent-models
```

Crea PR (cuerpo detallado para auditoría):

```bash
gh pr create \
  --base main \
  --head bloque-03-eloquent-models \
  --title "Bloque 03 — Eloquent models for 14 legacy tables (with Latin1 cast + tests)" \
  --body "$(cat <<'BODY'
## Resumen

Genera 13 Eloquent Models para las tablas legacy (omitimos \`session\`), un Cast custom para resolver mojibake latin1↔utf8, y migrations test-only para crear las tablas en SQLite memoria durante tests.

Schema fuente: ARCHITECTURE.md §5.1 verificada contra INFORMATION_SCHEMA en producción durante Bloque 02. Decisiones derivadas: ADR-0006 (correctivo), ADR-0007 (Modulo constants/scopes), ADR-0008 (auth field corrections).

## Archivos generados

- \`app/Casts/Latin1String.php\` + tests
- \`database/migrations/legacy_test/...create_legacy_tables.php\` (no se carga en prod)
- \`app/Models/{Piv,Averia,Asignacion,Correctivo,Revision,Tecnico,Operador,Modulo,PivImagen,InstaladorPiv,DesinstaladoPiv,ReinstaladoPiv,U1}.php\`
- \`database/factories/{...}Factory.php\` por modelo
- \`tests/Feature/Models/{...}Test.php\` smoke tests por modelo

## Convenciones aplicadas

- PKs \`<tabla>_id\` (excepto \`u1.user_id\`) — ADR-0008.
- \`Tecnico\` y \`Operador\` ocultan \`clave\` (NO \`password\`) — ADR-0008.
- \`Modulo\` expone constantes \`TIPO_*\` y scopes (\`municipios()\`, \`industrias()\`, \`checks()\`) — ADR-0007.
- \`Asignacion\` tiene accessor \`piv\` que delega a \`averia.piv\` (sin \`piv_id\` directo en schema legacy).
- \`Piv::scopeForOperador(int)\` cubre los 3 slots (operador_id, _2, _3).
- \`Latin1String\` cast aplicado a campos de texto con caracteres no-ASCII (nombres, direcciones, notas, etc.).

## Tests

- \`./vendor/bin/pest\`: N tests passing (CI verifica).
- Tests obligatorios cubiertos del DoD copilot-instructions.md (los relacionados con schema):
  - \`legacy_tables_present_with_correct_columns\`
  - \`u1_user_id_pk_works_with_lv_users_lookup\` (preparación para Bloque 06)
  - Tests de Modulo scopes y constantes.
  - Tests de \$hidden=clave en Tecnico/Operador.

## CI esperado

Los 3 jobs (PHP 8.2, PHP 8.3, Vite) deben pasar. La migration legacy_test corre en SQLite memoria de cada job.

## Lo que NO incluye este PR (deferido a bloques siguientes)

- Auth guard (\`LegacyHashGuard\`) — Bloque 06.
- \`lv_correctivo_imagen\` migration — Bloque 04 (junto al resto de \`lv_*\`).
- Tests de relaciones cross-model exhaustivos — surgirán en Bloques 07/08/09.
- Charset accessor en columnas que no he marcado — añadir cuando se descubran al usar.
BODY
)"
```

Espera CI:

```bash
sleep 8
PR_NUM=$(gh pr list --head bloque-03-eloquent-models --json number --jq '.[0].number')
echo "PR número: $PR_NUM — URL:"
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

Cuando todo verde:

```
✅ Qué he hecho:
   - 13 Eloquent Models creados (rutas app/Models/...).
   - 1 Cast custom Latin1String + 6 tests.
   - 1 migration legacy_test que crea 14 tablas en SQLite memoria.
   - 1 archivo tests/TestCase.php con loadMigrationsFrom legacy_test.
   - N factories + N smoke tests por modelo.
   - 6-8 commits separados por fase.
   - Push + PR creado: [URL].
   - CI [RUN_ID]: 3/3 verde (PHP 8.2, PHP 8.3, Vite build).

⏳ Qué falta:
   - Bloque 04 (lv_* migrations: lv_users, lv_correctivo_imagen, etc).
   - Tests de relaciones complejas (ej. Asignacion.piv accessor con factory tree completo).
   - Charset accessor en columnas que no he previsto.

❓ Qué necesito del usuario:
   - Confirmar URL del PR.
   - Decidir merge strategy (sugiero Rebase and merge, conservar granularidad de commits).
   - Mergear cuando esté revisado.
```

NO mergees el PR. Esa decisión es del usuario en GitHub web.

END PROMPT
```

---

## Lo que viene después de Bloque 03

- **Bloque 04** — Migrations para tablas internas Laravel `lv_*` (lv_users según ADR-0005, lv_correctivo_imagen según ADR-0006, lv_jobs/cache/sessions/etc.).
- **Bloque 05** — Filament install + custom theme con tokens de DESIGN.md + crear primer admin user vía tinker (insert manual en `lv_users` con `legacy_kind='admin'` apuntando a `u1.user_id`).
- **Bloque 06** — `LegacyHashGuard::attempt()` con mapeo `$tableMeta` (ADR-0008) + lazy creation (ADR-0005) + rate limiting + tests obligatorios.
