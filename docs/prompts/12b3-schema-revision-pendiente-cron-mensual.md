# Bloque 12b.3 — Schema `lv_revision_pendiente` + cron mensual + hook cierre

## Contexto

Bloque 12b.2 (PR #35, 4 may mediodía) entregó el `CalendarioLaboralService`. Bloque 12b.3
entrega la **base estructural de la planificación mensual preventiva**: una tabla nueva
+ el cron que cada día 1 del mes la rellena automáticamente con los ~484 paneles
activos no archivados. Sin UI admin (eso es 12b.4), sin distribución automática de
fechas planificadas (eso lo decide admin día a día en 12b.4).

El **flujo completo del módulo 12b** queda así tras 12b.3:

1. **Día 1 del mes 06:00 Madrid** — cron `lv:generate-revision-pendiente-monthly`
   crea ~484 filas `lv_revision_pendiente` con `status=pendiente`. Los paneles que
   tenían fila incompleta el mes anterior arrastran `carry_over_origen_id`.
2. **Día a día** — admin abre 12b.4 (futuro), por cada fila pendiente decide:
   - "Verificada remoto" — admin marca OK desde su app externa.
   - "Requiere visita" + fecha — admin marca y promueve a `asignacion` legacy.
   - "Excepción" — panel en obras / retirado.
3. **Cron daily 06:00** (12b.4) — promueve filas `requiere_visita` con `fecha_planificada == today` a `asignacion` legacy + `lv_revision_pendiente.asignacion_id` set.
4. **Técnico cierra revisión via PWA** (Bloque 11d) → `AsignacionCierreService::cerrar()` ya escribe `revision`/`correctivo` + `asignacion.status=2`. **AÑADIDO en este bloque**: hook que busca `lv_revision_pendiente.asignacion_id` y marca `status=completada`.
5. Final del mes (12b.6) — reporte contractual.

## Decisiones cerradas con el usuario (4 may 2026 mediodía)

Confirmadas todas vía OK explícito antes del prompt:

1. **Status enum**: `pendiente` (default), `verificada_remoto`, `requiere_visita`, `excepcion`, `completada`.
2. **UNIQUE compuesto** `(piv_id, periodo_year, periodo_month)` — un panel solo tiene una fila por mes.
3. **Cron mensual día 1 a 06:00 Europe/Madrid**, idempotente. Genera para todos `Piv::notArchived()`.
4. **Carry over**: filas mes-anterior con status en `[pendiente, requiere_visita, excepcion]` apuntan vía `carry_over_origen_id` desde la fila nueva del mes en curso. Status `verificada_remoto` y `completada` NO arrastran (panel ya satisfecho).
5. **Hook cierre PWA**: `AsignacionCierreService::cerrar()` busca `lv_revision_pendiente.asignacion_id == $asignacion->asignacion_id` y marca completada. Best-effort (no rompe si no hay fila).

## Restricciones inviolables

- **NO modificar tablas legacy** (`piv`, `modulo`, `tecnico`, `operador`, `asignacion`, `correctivo`, `revision`, `averia`, etc.). FK lógicas a legacy SIN constraint físico (ADR-0002 coexistencia).
- **NO UI** (Filament Resource para `lv_revision_pendiente` es Bloque 12b.4). Solo schema + service + console command + hook.
- **NO cron daily promotor** a `asignacion` legacy. Eso es 12b.4. Este bloque solo crea el cron MENSUAL.
- **NO distribuir `fecha_planificada` automáticamente**. El cron mensual deja todas las filas con `fecha_planificada=NULL`. Admin la asigna en 12b.4.
- **NO mutar status de filas existentes** durante el cron mensual. Sólo INSERT + UPDATE de `carry_over_origen_id` (cuando `wasRecentlyCreated`). Si ya existe fila para `(piv_id, year, month)` con status decidido, no la toca.
- **PHP 8.2 floor** (composer platform pin). Sin features 8.3+.
- **Tests Pest verde obligatorio**. Suite actual 240 → ~257-260 verde.
- **CI 3/3 verde** antes de PR ready.
- **Pint clean**.
- **DESIGN.md NO aplica** (sin UI).
- **Cero paquete nuevo** en composer.json.

## Plan de cambios

### Step 1 — Migration `lv_revision_pendiente`

`database/migrations/2026_05_04_000000_create_lv_revision_pendiente_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_revision_pendiente', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('piv_id')->comment('FK lógica a piv.piv_id (sin constraint físico, ADR-0002)');
            $t->unsignedSmallInteger('periodo_year');
            $t->unsignedTinyInteger('periodo_month');
            $t->enum('status', [
                'pendiente',
                'verificada_remoto',
                'requiere_visita',
                'excepcion',
                'completada',
            ])->default('pendiente');
            $t->date('fecha_planificada')->nullable()->comment('Set solo cuando status=requiere_visita en 12b.4');
            $t->unsignedBigInteger('decision_user_id')->nullable();
            $t->timestamp('decision_at')->nullable();
            $t->text('decision_notas')->nullable();
            $t->unsignedBigInteger('carry_over_origen_id')->nullable()->comment('Self-FK a fila del mes anterior si vino por carry');
            $t->unsignedInteger('asignacion_id')->nullable()->comment('FK lógica a asignacion.asignacion_id legacy (set por 12b.4)');
            $t->timestamps();

            $t->unique(['piv_id', 'periodo_year', 'periodo_month'], 'uniq_piv_periodo');
            $t->index('status', 'idx_status');
            $t->index(['periodo_year', 'periodo_month'], 'idx_periodo');
            $t->index('fecha_planificada', 'idx_fecha_planificada');
            $t->index('asignacion_id', 'idx_asignacion_id');

            // FKs físicas SOLO entre lv_*: self + lv_users.
            $t->foreign('carry_over_origen_id', 'fk_carry_over_origen')
                ->references('id')->on('lv_revision_pendiente')
                ->nullOnDelete();
            $t->foreign('decision_user_id', 'fk_decision_user')
                ->references('id')->on('lv_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_revision_pendiente');
    }
};
```

**NO** añadir FK física a `piv` ni a `asignacion` (ADR-0002 coexistencia).

### Step 2 — Modelo `App\Models\LvRevisionPendiente`

`app/Models/LvRevisionPendiente.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LvRevisionPendienteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fila por (panel, mes). Crea el cron mensual el día 1.
 * Decide admin en 12b.4. Marca completada el hook de cierre PWA.
 */
final class LvRevisionPendiente extends Model
{
    use HasFactory;

    protected $table = 'lv_revision_pendiente';

    public const STATUS_PENDIENTE = 'pendiente';
    public const STATUS_VERIFICADA_REMOTO = 'verificada_remoto';
    public const STATUS_REQUIERE_VISITA = 'requiere_visita';
    public const STATUS_EXCEPCION = 'excepcion';
    public const STATUS_COMPLETADA = 'completada';

    public const STATUSES_INCOMPLETAS = [
        self::STATUS_PENDIENTE,
        self::STATUS_REQUIERE_VISITA,
        self::STATUS_EXCEPCION,
    ];

    public const STATUSES_SATISFECHAS = [
        self::STATUS_VERIFICADA_REMOTO,
        self::STATUS_COMPLETADA,
    ];

    protected $fillable = [
        'piv_id',
        'periodo_year',
        'periodo_month',
        'status',
        'fecha_planificada',
        'decision_user_id',
        'decision_at',
        'decision_notas',
        'carry_over_origen_id',
        'asignacion_id',
    ];

    protected $casts = [
        'piv_id' => 'int',
        'periodo_year' => 'int',
        'periodo_month' => 'int',
        'fecha_planificada' => 'date',
        'decision_user_id' => 'int',
        'decision_at' => 'datetime',
        'carry_over_origen_id' => 'int',
        'asignacion_id' => 'int',
    ];

    protected static function newFactory(): LvRevisionPendienteFactory
    {
        return LvRevisionPendienteFactory::new();
    }

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }

    public function decisionUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_user_id');
    }

    public function carryOverOrigen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carry_over_origen_id');
    }

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(Asignacion::class, 'asignacion_id', 'asignacion_id');
    }

    public function scopeIncompletas(Builder $query): void
    {
        $query->whereIn('status', self::STATUSES_INCOMPLETAS);
    }

    public function scopeSatisfechas(Builder $query): void
    {
        $query->whereIn('status', self::STATUSES_SATISFECHAS);
    }

    public function scopeDelMes(Builder $query, int $year, int $month): void
    {
        $query->where('periodo_year', $year)->where('periodo_month', $month);
    }

    public function isCarryOver(): bool
    {
        return $this->carry_over_origen_id !== null;
    }
}
```

### Step 3 — Reverse relation en `Piv` model

Añadir al modelo `app/Models/Piv.php` (sin tocar nada existente):

```php
public function revisionesPendientes(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(LvRevisionPendiente::class, 'piv_id', 'piv_id');
}
```

### Step 4 — Factory `database/factories/LvRevisionPendienteFactory.php`

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LvRevisionPendiente>
 */
class LvRevisionPendienteFactory extends Factory
{
    protected $model = LvRevisionPendiente::class;

    public function definition(): array
    {
        return [
            'piv_id' => Piv::factory(),
            'periodo_year' => 2026,
            'periodo_month' => 5,
            'status' => LvRevisionPendiente::STATUS_PENDIENTE,
            'fecha_planificada' => null,
            'decision_user_id' => null,
            'decision_at' => null,
            'decision_notas' => null,
            'carry_over_origen_id' => null,
            'asignacion_id' => null,
        ];
    }

    public function pendiente(): self
    {
        return $this->state(fn () => ['status' => LvRevisionPendiente::STATUS_PENDIENTE]);
    }

    public function verificadaRemoto(): self
    {
        return $this->state(fn () => [
            'status' => LvRevisionPendiente::STATUS_VERIFICADA_REMOTO,
            'decision_at' => now(),
        ]);
    }

    public function requiereVisita(): self
    {
        return $this->state(fn () => [
            'status' => LvRevisionPendiente::STATUS_REQUIERE_VISITA,
            'fecha_planificada' => now()->toDateString(),
            'decision_at' => now(),
        ]);
    }

    public function excepcion(): self
    {
        return $this->state(fn () => [
            'status' => LvRevisionPendiente::STATUS_EXCEPCION,
            'decision_at' => now(),
        ]);
    }

    public function completada(): self
    {
        return $this->state(fn () => [
            'status' => LvRevisionPendiente::STATUS_COMPLETADA,
        ]);
    }
}
```

### Step 5 — Service `App\Services\RevisionPendienteSeederService`

`app/Services/RevisionPendienteSeederService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use Illuminate\Support\Facades\DB;

/**
 * Genera filas lv_revision_pendiente para los paneles activos no archivados.
 * Idempotente: re-ejecutar el mismo (year, month) no duplica.
 *
 * Carry over: filas del mes anterior con status incompleto (pendiente,
 * requiere_visita, excepcion) hacen que la fila nueva del mes en curso para
 * ese mismo panel apunte vía carry_over_origen_id.
 */
final class RevisionPendienteSeederService
{
    /**
     * @return array{created: int, carry_updated: int, already_existed: int, total_panels: int}
     */
    public function generarMes(int $year, int $month): array
    {
        return DB::transaction(function () use ($year, $month): array {
            $created = 0;
            $carryUpdated = 0;
            $alreadyExisted = 0;

            [$prevYear, $prevMonth] = $this->previousPeriod($year, $month);

            $previousIncompletas = LvRevisionPendiente::query()
                ->incompletas()
                ->delMes($prevYear, $prevMonth)
                ->get(['id', 'piv_id'])
                ->keyBy('piv_id');

            $totalPanels = 0;
            Piv::notArchived()->cursor()->each(function (Piv $piv) use (
                $year,
                $month,
                $previousIncompletas,
                &$created,
                &$carryUpdated,
                &$alreadyExisted,
                &$totalPanels,
            ) {
                $totalPanels++;

                $row = LvRevisionPendiente::firstOrCreate(
                    [
                        'piv_id' => $piv->piv_id,
                        'periodo_year' => $year,
                        'periodo_month' => $month,
                    ],
                    ['status' => LvRevisionPendiente::STATUS_PENDIENTE],
                );

                if ($row->wasRecentlyCreated) {
                    $created++;
                } else {
                    $alreadyExisted++;
                }

                if ($row->carry_over_origen_id === null) {
                    $previous = $previousIncompletas->get($piv->piv_id);
                    if ($previous !== null) {
                        $row->carry_over_origen_id = $previous->id;
                        $row->save();
                        $carryUpdated++;
                    }
                }
            });

            return [
                'created' => $created,
                'carry_updated' => $carryUpdated,
                'already_existed' => $alreadyExisted,
                'total_panels' => $totalPanels,
            ];
        });
    }

    /**
     * @return array{0: int, 1: int}  [year, month]
     */
    private function previousPeriod(int $year, int $month): array
    {
        if ($month === 1) {
            return [$year - 1, 12];
        }

        return [$year, $month - 1];
    }
}
```

**Notas**:

- `Piv::notArchived()` scope ya existe (Bloque 07e). Filtra fuera los 91 archivados.
- `cursor()` para no cargar todos los pivs en memoria (eficiente con 484+ filas, aunque
  en prod todavía es pequeño).
- Loop interno usa `firstOrCreate` por la UNIQUE compuesta. Si ya existe → no toca status.
- Carry over actualiza solo si `carry_over_origen_id === null` (idempotente para re-runs
  intra-mes — si admin ya tomó decisiones que cambian el carry, no se machaca).
- Total panels devuelto es informativo (=created+already_existed).

### Step 6 — Console command `app/Console/Commands/GenerateRevisionPendienteMonthly.php`

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RevisionPendienteSeederService;
use Illuminate\Console\Command;

final class GenerateRevisionPendienteMonthly extends Command
{
    protected $signature = 'lv:generate-revision-pendiente-monthly
                            {--year= : Año (default = año actual Europe/Madrid)}
                            {--month= : Mes 1-12 (default = mes actual Europe/Madrid)}';

    protected $description = 'Genera filas lv_revision_pendiente para los paneles activos del mes indicado. Idempotente.';

    public function handle(RevisionPendienteSeederService $svc): int
    {
        $now = now('Europe/Madrid');
        $year = (int) ($this->option('year') ?? $now->year);
        $month = (int) ($this->option('month') ?? $now->month);

        if ($month < 1 || $month > 12) {
            $this->error("Mes inválido: {$month}. Debe estar entre 1 y 12.");

            return self::INVALID;
        }

        $this->info("Generando lv_revision_pendiente para {$year}-{$month}...");

        $result = $svc->generarMes($year, $month);

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['total_panels (Piv::notArchived())', $result['total_panels']],
                ['created (filas nuevas)', $result['created']],
                ['already_existed (idempotente)', $result['already_existed']],
                ['carry_updated (carry_over_origen_id set)', $result['carry_updated']],
            ],
        );

        return self::SUCCESS;
    }
}
```

### Step 7 — Schedule `routes/console.php`

Añadir AL FINAL de `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('lv:generate-revision-pendiente-monthly')
    ->monthlyOn(1, '06:00')
    ->timezone('Europe/Madrid')
    ->onOneServer()
    ->name('lv-generate-revision-pendiente-monthly');
```

**Nota sobre cron en SiteGround**: ADR-0001 §Consequences negativas registra que SiteGround GoGeek **NO expone `crontab` por SSH**, gestión exclusiva via Site Tools UI. Bloque 14 cubrirá la configuración del cron `* * * * * cd $HOME/laravel-app && php artisan schedule:run`. Mientras tanto, este Bloque 12b.3 deja el cron Laravel registrado y testeable pero no activo en prod hasta Bloque 14.

### Step 8 — Hook en `AsignacionCierreService::cerrar()`

Modificar `app/Services/AsignacionCierreService.php`. **Punto exacto**: justo después de
`$asignacion->update(['status' => 2])` (línea 51 en HEAD `335c195`) y antes del
`return $result` (línea 53).

```php
            // NO tocamos averia.notas: pertenece al operador que reportó la avería.
            $asignacion->update(['status' => 2]);

            // [12b.3] Hook: si esta asignación vino del cron daily promotor (12b.4) y
            // tiene fila lv_revision_pendiente asociada, marcarla completada.
            // Best-effort: si no hay fila (asignación creada manual fuera del flujo
            // mensual), no rompe nada.
            \App\Models\LvRevisionPendiente::query()
                ->where('asignacion_id', $asignacion->asignacion_id)
                ->where('status', '!=', \App\Models\LvRevisionPendiente::STATUS_COMPLETADA)
                ->update([
                    'status' => \App\Models\LvRevisionPendiente::STATUS_COMPLETADA,
                    'updated_at' => now(),
                ]);

            return $result;
```

**Importante**:
- Usa `query()->where()->where()->update()` en lugar de `LvRevisionPendiente::find` porque
  el lookup por `asignacion_id` (FK lógica legacy) no es PK. Bulk update sin hidratar.
- Filtro `status != completada` para idempotencia (re-cierre por el flujo no resetea ni machaca timestamps si ya estaba completada).
- Imports `use App\Models\LvRevisionPendiente;` arriba en lugar de FQN inline si Pint lo prefiere.

### Step 9 — Tests Pest

#### Test 9.1 — Schema y constantes

`tests/Feature/Models/LvRevisionPendienteSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;

beforeEach(function () {
    expect(Schema::hasTable('lv_revision_pendiente'))->toBeTrue();
});

it('schema tiene las columnas esperadas', function () {
    $cols = ['id', 'piv_id', 'periodo_year', 'periodo_month', 'status',
             'fecha_planificada', 'decision_user_id', 'decision_at',
             'decision_notas', 'carry_over_origen_id', 'asignacion_id',
             'created_at', 'updated_at'];
    foreach ($cols as $c) {
        expect(Schema::hasColumn('lv_revision_pendiente', $c))->toBeTrue();
    }
});

it('UNIQUE compuesto piv_id periodo_year periodo_month previene duplicados', function () {
    $piv = Piv::factory()->create();
    LvRevisionPendiente::factory()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 5,
    ]);

    expect(fn () => LvRevisionPendiente::factory()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 5,
    ]))->toThrow(QueryException::class);
});

it('STATUSES_INCOMPLETAS contiene exactamente pendiente requiere_visita excepcion', function () {
    expect(LvRevisionPendiente::STATUSES_INCOMPLETAS)
        ->toBe([
            LvRevisionPendiente::STATUS_PENDIENTE,
            LvRevisionPendiente::STATUS_REQUIERE_VISITA,
            LvRevisionPendiente::STATUS_EXCEPCION,
        ]);
});

it('STATUSES_SATISFECHAS contiene exactamente verificada_remoto completada', function () {
    expect(LvRevisionPendiente::STATUSES_SATISFECHAS)
        ->toBe([
            LvRevisionPendiente::STATUS_VERIFICADA_REMOTO,
            LvRevisionPendiente::STATUS_COMPLETADA,
        ]);
});

it('relaciones piv decisionUser carryOverOrigen asignacion existen', function () {
    $piv = Piv::factory()->create();
    $row = LvRevisionPendiente::factory()->for($piv, 'piv')->create();

    expect($row->piv)->toBeInstanceOf(Piv::class);
    expect($row->piv->piv_id)->toBe($piv->piv_id);
});

it('scope incompletas filtra solo los 3 status', function () {
    Piv::factory()->count(5)->create()->each(fn ($p, $i) =>
        LvRevisionPendiente::factory()->create([
            'piv_id' => $p->piv_id,
            'status' => [
                LvRevisionPendiente::STATUS_PENDIENTE,
                LvRevisionPendiente::STATUS_VERIFICADA_REMOTO,
                LvRevisionPendiente::STATUS_REQUIERE_VISITA,
                LvRevisionPendiente::STATUS_EXCEPCION,
                LvRevisionPendiente::STATUS_COMPLETADA,
            ][$i],
        ])
    );

    expect(LvRevisionPendiente::query()->incompletas()->count())->toBe(3);
});

it('scope delMes filtra por year y month', function () {
    LvRevisionPendiente::factory()->create(['periodo_year' => 2026, 'periodo_month' => 5]);
    LvRevisionPendiente::factory()->create(['periodo_year' => 2026, 'periodo_month' => 6]);
    LvRevisionPendiente::factory()->create(['periodo_year' => 2025, 'periodo_month' => 5]);

    expect(LvRevisionPendiente::query()->delMes(2026, 5)->count())->toBe(1);
});
```

#### Test 9.2 — Service `RevisionPendienteSeederService`

`tests/Unit/Services/RevisionPendienteSeederServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\LvPivArchived;
use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use App\Services\RevisionPendienteSeederService;

beforeEach(function () {
    $this->svc = new RevisionPendienteSeederService();
});

it('crea una fila por cada Piv no archivado', function () {
    Piv::factory()->count(5)->create();
    $archived = Piv::factory()->create();
    LvPivArchived::factory()->create(['piv_id' => $archived->piv_id]);

    $result = $this->svc->generarMes(2026, 5);

    expect($result['created'])->toBe(5);
    expect($result['total_panels'])->toBe(5); // archived NO cuenta
    expect(LvRevisionPendiente::count())->toBe(5);
});

it('es idempotente: re-run no duplica', function () {
    Piv::factory()->count(3)->create();

    $first = $this->svc->generarMes(2026, 5);
    expect($first['created'])->toBe(3);

    $second = $this->svc->generarMes(2026, 5);
    expect($second['created'])->toBe(0);
    expect($second['already_existed'])->toBe(3);
    expect(LvRevisionPendiente::count())->toBe(3);
});

it('carry over: panel pendiente mes anterior recibe carry_over_origen_id', function () {
    $piv = Piv::factory()->create();
    $previous = LvRevisionPendiente::factory()->pendiente()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 4,
    ]);

    $this->svc->generarMes(2026, 5);

    $current = LvRevisionPendiente::where('piv_id', $piv->piv_id)
        ->where('periodo_year', 2026)
        ->where('periodo_month', 5)
        ->first();

    expect($current)->not->toBeNull();
    expect($current->carry_over_origen_id)->toBe($previous->id);
    expect($current->status)->toBe(LvRevisionPendiente::STATUS_PENDIENTE);
});

it('carry over: panel requiere_visita mes anterior arrastra', function () {
    $piv = Piv::factory()->create();
    $previous = LvRevisionPendiente::factory()->requiereVisita()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 4,
    ]);

    $this->svc->generarMes(2026, 5);

    $current = LvRevisionPendiente::where('piv_id', $piv->piv_id)->delMes(2026, 5)->first();
    expect($current->carry_over_origen_id)->toBe($previous->id);
});

it('carry over: panel excepcion mes anterior arrastra', function () {
    $piv = Piv::factory()->create();
    $previous = LvRevisionPendiente::factory()->excepcion()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 4,
    ]);

    $this->svc->generarMes(2026, 5);

    $current = LvRevisionPendiente::where('piv_id', $piv->piv_id)->delMes(2026, 5)->first();
    expect($current->carry_over_origen_id)->toBe($previous->id);
});

it('carry over: panel verificada_remoto mes anterior NO arrastra', function () {
    $piv = Piv::factory()->create();
    LvRevisionPendiente::factory()->verificadaRemoto()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 4,
    ]);

    $this->svc->generarMes(2026, 5);

    $current = LvRevisionPendiente::where('piv_id', $piv->piv_id)->delMes(2026, 5)->first();
    expect($current)->not->toBeNull();
    expect($current->carry_over_origen_id)->toBeNull();
});

it('carry over: panel completada mes anterior NO arrastra', function () {
    $piv = Piv::factory()->create();
    LvRevisionPendiente::factory()->completada()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2026,
        'periodo_month' => 4,
    ]);

    $this->svc->generarMes(2026, 5);

    $current = LvRevisionPendiente::where('piv_id', $piv->piv_id)->delMes(2026, 5)->first();
    expect($current->carry_over_origen_id)->toBeNull();
});

it('cruce de año enero busca diciembre del año anterior', function () {
    $piv = Piv::factory()->create();
    $previous = LvRevisionPendiente::factory()->pendiente()->create([
        'piv_id' => $piv->piv_id,
        'periodo_year' => 2025,
        'periodo_month' => 12,
    ]);

    $this->svc->generarMes(2026, 1);

    $current = LvRevisionPendiente::where('piv_id', $piv->piv_id)->delMes(2026, 1)->first();
    expect($current->carry_over_origen_id)->toBe($previous->id);
});
```

#### Test 9.3 — Console command

`tests/Feature/Console/GenerateRevisionPendienteMonthlyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\LvRevisionPendiente;
use App\Models\Piv;

it('comando con --year y --month genera filas para ese periodo', function () {
    Piv::factory()->count(3)->create();

    $this->artisan('lv:generate-revision-pendiente-monthly', [
        '--year' => 2026,
        '--month' => 5,
    ])->assertSuccessful();

    expect(LvRevisionPendiente::delMes(2026, 5)->count())->toBe(3);
});

it('comando sin opciones usa now Europe/Madrid', function () {
    Piv::factory()->count(2)->create();
    $now = now('Europe/Madrid');

    $this->artisan('lv:generate-revision-pendiente-monthly')->assertSuccessful();

    expect(LvRevisionPendiente::delMes($now->year, $now->month)->count())->toBe(2);
});

it('comando con mes inválido devuelve INVALID', function () {
    $this->artisan('lv:generate-revision-pendiente-monthly', [
        '--year' => 2026,
        '--month' => 13,
    ])->assertFailed();
});

it('cron mensual está registrado en schedule', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($e) => $e->command);

    expect($events->contains(fn ($c) =>
        is_string($c) && str_contains($c, 'lv:generate-revision-pendiente-monthly')
    ))->toBeTrue();
});
```

#### Test 9.4 — Hook en AsignacionCierreService

`tests/Unit/Services/AsignacionCierreHookTest.php` (o añadir tests al existente
`AsignacionCierreServiceTest.php` si lo prefiere Copilot):

```php
<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Services\AsignacionCierreService;

it('hook: cerrar asignación con lv_revision_pendiente asociada marca completada', function () {
    $piv = Piv::factory()->create();
    $tecnico = Tecnico::factory()->create();
    $averia = Averia::factory()->for($piv, 'piv')->create();
    $asignacion = Asignacion::factory()
        ->for($averia, 'averia')
        ->for($tecnico, 'tecnico')
        ->create([
            'tipo' => Asignacion::TIPO_REVISION,
            'status' => 1,
        ]);

    $rp = LvRevisionPendiente::factory()->requiereVisita()->create([
        'piv_id' => $piv->piv_id,
        'asignacion_id' => $asignacion->asignacion_id,
    ]);

    app(AsignacionCierreService::class)->cerrar($asignacion, [
        'fecha' => '2026-05-04',
        'aspecto' => 'OK',
        'funcionamiento' => 'OK',
    ]);

    expect($rp->fresh()->status)->toBe(LvRevisionPendiente::STATUS_COMPLETADA);
});

it('hook: cerrar asignación SIN lv_revision_pendiente no rompe', function () {
    $piv = Piv::factory()->create();
    $tecnico = Tecnico::factory()->create();
    $averia = Averia::factory()->for($piv, 'piv')->create();
    $asignacion = Asignacion::factory()
        ->for($averia, 'averia')
        ->for($tecnico, 'tecnico')
        ->create([
            'tipo' => Asignacion::TIPO_REVISION,
            'status' => 1,
        ]);

    expect(fn () => app(AsignacionCierreService::class)->cerrar($asignacion, [
        'fecha' => '2026-05-04',
        'aspecto' => 'OK',
    ]))->not->toThrow(\Throwable::class);

    expect($asignacion->fresh()->status)->toBe(2);
});

it('hook: re-cerrar idempotente no machaca completada', function () {
    // Setup: crear lv_revision_pendiente status=completada manualmente.
    $piv = Piv::factory()->create();
    $tecnico = Tecnico::factory()->create();
    $averia = Averia::factory()->for($piv, 'piv')->create();
    $asignacion = Asignacion::factory()
        ->for($averia, 'averia')
        ->for($tecnico, 'tecnico')
        ->create(['tipo' => Asignacion::TIPO_REVISION, 'status' => 1]);

    $rp = LvRevisionPendiente::factory()->completada()->create([
        'piv_id' => $piv->piv_id,
        'asignacion_id' => $asignacion->asignacion_id,
    ]);
    $originalUpdatedAt = $rp->updated_at;
    sleep(1); // garantizar que un update tendría timestamp distinto.

    app(AsignacionCierreService::class)->cerrar($asignacion, [
        'fecha' => '2026-05-04',
        'aspecto' => 'OK',
    ]);

    // Filtro `status != completada` impide update — updated_at no cambia.
    expect($rp->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});
```

### Step 10 — Smoke local (text-only)

Después de tests verde:

```bash
php artisan migrate
php artisan tinker
```

```php
use App\Models\LvRevisionPendiente;
use App\Models\Piv;

// Seed 5 paneles factory.
Piv::factory()->count(5)->create();

// Run service.
$result = app(\App\Services\RevisionPendienteSeederService::class)->generarMes(2026, 5);
print_r($result);
// Esperado: created=5, already_existed=0, carry_updated=0, total_panels=5

// Re-run.
$result2 = app(\App\Services\RevisionPendienteSeederService::class)->generarMes(2026, 5);
print_r($result2);
// Esperado: created=0, already_existed=5, carry_updated=0, total_panels=5
```

## DoD

- [ ] Migration `lv_revision_pendiente` creada con columnas + UNIQUE + indexes + 2 FKs (carry self-FK + decision_user → lv_users).
- [ ] Modelo `LvRevisionPendiente` con constantes, relaciones, scopes, factory.
- [ ] `Piv::revisionesPendientes()` HasMany añadido.
- [ ] Service `RevisionPendienteSeederService::generarMes()` idempotente con carry over.
- [ ] Console command `lv:generate-revision-pendiente-monthly` con `--year` `--month` opciones.
- [ ] Schedule mensual día 1 06:00 Europe/Madrid registrado en `routes/console.php`.
- [ ] Hook en `AsignacionCierreService::cerrar()` actualiza `lv_revision_pendiente` por `asignacion_id`.
- [ ] Tests Pest verde: ~17-19 nuevos. Suite total 240 → ≥257 verde.
- [ ] CI 3/3 verde.
- [ ] Pint clean.
- [ ] Smoke tinker text-only ejecutado.
- [ ] PR descripción con: schema columnas + cron schedule + decisiones cerradas (los 5 puntos del prompt).

## Smoke real obligatorio post-merge

**Antes** del smoke prod: backup fresh cifrado (igual que pre-12b.1, runbook nuevo
`docs/runbooks/backups/2026-05-XX-pre-bloque-12b3.md`).

**Smoke**:

1. `php artisan migrate --pretend` → revisar SQL (debe ser CREATE TABLE `lv_revision_pendiente`, cero ALTER legacy).
2. `php artisan migrate --force` real.
3. `php artisan lv:generate-revision-pendiente-monthly --year=2026 --month=5` → output esperado: `total_panels` = `Piv::notArchived()->count()` (esperado 484 ahora mismo, verificar al momento). `created` igual a `total_panels` (primera vez). `carry_updated=0` (no hay mes anterior).
4. Re-run mismo comando → `created=0`, `already_existed=484`. Idempotente.
5. Verificar via tinker:
   ```php
   LvRevisionPendiente::delMes(2026, 5)->count(); // = 484
   LvRevisionPendiente::delMes(2026, 5)->incompletas()->count(); // = 484
   LvRevisionPendiente::delMes(2026, 5)->whereNotNull('carry_over_origen_id')->count(); // = 0
   ```
6. Manual: marca 1 fila como `completada` via tinker. Run para junio:
   ```php
   $row = LvRevisionPendiente::delMes(2026, 5)->first();
   $row->update(['status' => LvRevisionPendiente::STATUS_COMPLETADA]);
   $piv_id_completada = $row->piv_id;
   
   app(\App\Services\RevisionPendienteSeederService::class)->generarMes(2026, 6);
   
   // Verificar: junio crea 484 filas (created), 0 carry_updated para piv_id_completada
   $junio_completada = LvRevisionPendiente::where('piv_id', $piv_id_completada)->delMes(2026, 6)->first();
   expect($junio_completada->carry_over_origen_id)->toBeNull(); // NO arrastra
   ```
7. Marca otra fila mes 5 como `pendiente` (default) y reproduce el flow para confirmar carry-over OK.
8. **Cleanup decisión**: las filas mayo 2026 quedan en prod como uso real (484 filas listas para 12b.4). Las filas junio 2026 del smoke borrarlas (uso de prueba):
   ```php
   LvRevisionPendiente::delMes(2026, 6)->delete();
   ```

## Riesgos y decisiones diferidas (cubrir en REPORTE FINAL)

1. **Cron NO activo en prod hasta Bloque 14** — registrado en Schedule pero SiteGround
   GoGeek no expone crontab por SSH. Bloque 14 cubrirá `* * * * * php artisan schedule:run` via Site Tools UI. Mientras tanto, `lv:generate-revision-pendiente-monthly` se invoca manualmente.
2. **475 vs 484** — usuario dijo "475 paneles activos". Conteo actual `Piv::notArchived()->count()` puede ser 484 (575 - 91 archivados). Diferencia = paneles que no están archivados pero usuario considera "no activos" (status=0 o similar). Decisión: el cron incluye TODOS `notArchived`. Si en review el usuario quiere filtrar también por `Piv::status` u otra columna, ajuste de 1 línea.
3. **Idempotencia de carry over** — cuando admin ya tomó decisiones intra-mes y el cron se re-ejecuta el mismo mes, `carry_over_origen_id` ya está set y NO se machaca. Pero si un panel aún no tenía carry_over (porque la fila se creó sin mes anterior incompleto) y aparece nueva fila del mes anterior incompleta DESPUÉS, el re-run NO actualiza el carry_over. Edge case improbable (admin no debería marcar una fila como pendiente retroactivamente).
4. **Mes 1 cruce año** — `previousPeriod(2026, 1)` devuelve `[2025, 12]`. Test específico cubre.
5. **`fecha_planificada` queda NULL** — el cron mensual NO la asigna. Eso es 12b.4. Filas con `requiere_visita` y `fecha_planificada=NULL` son inválidas en estado consistente; se gestiona en 12b.4.

## REPORTE FINAL (formato esperado)

```
## Bloque 12b.3 — REPORTE FINAL

### Estado
- Branch: bloque-12b3-revision-pendiente-cron-mensual
- Commits: N
- Tests: 240 → 258 verde (~18 nuevos: schema + service + command + hook).
- CI: 3/3 verde sobre HEAD <hash>
- Pint: clean
- Smoke tinker: ejecutado, output coincide con expectativa

### Decisiones aplicadas
- 5 puntos cerrados con usuario antes del prompt (status enum, schema, cron mensual, carry over, hook PWA).
- Cron schedule registrado pero NO activo en prod hasta Bloque 14.

### Riesgos/pendientes para review
- Verificar `Piv::notArchived()->count()` actual en prod antes del smoke (484 esperado).
- Decidir si carry over necesita re-run anti-edge-case (no urgente).

### Pivots respecto al prompt
- (si los hubo, listar y justificar)
```

---

## Aplicación de la checklist obligatoria (memoria proyecto)

| Sección | Aplicado | Cómo |
|---|---|---|
| 1. Compatibilidad framework | ✓ | Migration MySQL standard, Eloquent puro, console command Laravel built-in. Sin Filament, Livewire, RelationManager (los 3 conflictivos del proyecto). |
| 2. Inferir de app vieja | N/A | App vieja PHP 2014 NO tiene revisión preventiva mensual estructurada (las "REVISION MENSUAL" eran averías-stub tipo=2 con notas — bug ADR-0004). Feature nueva 100%. |
| 3. Smoke real obligatorio | ✓ | Backup prod fresh cifrado pre-migrate. Smoke real con `lv:generate-revision-pendiente-monthly` ejecutándose contra `Piv::notArchived()` reales. Verificación tinker post-cron. Cleanup decisión: mayo queda permanente, junio se borra. |
| 4. Test pivots = banderazo rojo | ✓ | Tests con factories Piv + LvPivArchived realistas. Hook test usa `app(AsignacionCierreService::class)` real, no mock. Si Copilot pivota un test (p. ej. cambia `firstOrCreate` por upsert), revisar si la integración se mantiene o si debilita coverage. |
| 5. Datos prod-shaped | ✓ | Tests cubren: paneles archivados (no cuentan), carry over con 5 status diferentes, cruce año (enero busca diciembre anterior), idempotencia, hook con/sin lv_revision_pendiente. |
