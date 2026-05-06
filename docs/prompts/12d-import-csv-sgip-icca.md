# Bloque 12d — Importación CSV SGIP/ICCA (averías) con política ADD + mark inactive

## Contexto

Tras Bloque 12c (PR #40 + smoke prod 5 may mañana) la planificación preventiva tiene 5 rutas oficiales + 40 municipios asignados. Ahora entra la **mitad correctiva**: importar las averías ICCA exportadas via SGIP que admin descarga cada mañana.

Recordatorio del flujo real (ARCHITECTURE.md §13):
> Cada mañana admin descarga CSV averías ICCA del día asignadas a Winfin, las cruza con preventivos cercanos, construye ruta mixta, técnico ejecuta, día siguiente admin cierra ICCA + deriva las que no son Winfin.

Bloque 12d entrega el **import del CSV**. El cruce con preventivos y la ruta optimizada llegan en Bloque 12e.

## Decisiones cerradas con el usuario antes del prompt

**Estratégicas (cerradas el 5 may mediodía)**:
1. Política **ADD + mark inactive**: nuevas averías → INSERT, existentes → UPDATE, activas anteriores no presentes en CSV nuevo → `activa = false` (nunca DELETE, audit trail).
2. CSV SGIP es **foto del momento Winfin completa**: cada upload aplica los 3 movimientos sobre la foto entera.
3. **Audit trail**: guardar `fecha_import`, `archivo_origen` (filename), `imported_by_user_id` (admin que sube).

**Tácticas (cerradas el 5 may tarde, 4 puntos)**:

4. **Schema** `lv_averia_icca` con columnas exactas (ver Step 1).
5. **Match `piv.parada_cod`**: exacto string primero → numérico CAST si falla → `NULL + warn` si ambiguo (múltiple por sufijos A/B) o no-match.
6. **UI**: Page custom de upload + Resource read-only para browse. Tabla NO editable (averías se cierran fuera, en el SGIP real).
7. **Modal confirmación grande** antes de aplicar: cuenta cuántas se marcarán inactivas, exige confirm explícito.

## Datos reales del CSV `SGIP_winfin.csv` (5 may 2026)

- **28 averías** activas asignadas a Winfin.
- **7 columnas**: `Id`, `Categoría`, `Resumen`, `Estado`, `Descripción`, `NOTAS`, `Asignada a`.
- **4 categorías reales**: "Problemas de comunicación" (15), "Panel apagado" (9), "Problema de tiempos" (3), "Problema de audio" (1).
- **Estado** único: `asignada` (28/28).
- **Asignada a** único: `SGIP_winfin` (28/28).
- **Resumen** formato `PANEL XXXXX` (5 dígitos, sin sufijo letra normalmente — los sufijos A/B son del legacy `parada_cod`, no del SGIP).
- **NOTAS** pueden ser vacías o hasta 1492+ chars (hilo histórico CAU_ICCA/SGIP_winfin/SGIP_Indra con timestamps `@CAU_ICCA YYYY-MM-DD HH:MM\n...---\n@SGIP_winfin ...`).
- Cross-check con BD: 30 matches de `piv.parada_cod` para 27 IDs únicos del CSV (3 IDs matchean con 2 paneles cada uno por sufijos letra A/B).
- 1 ID del CSV es duplicado dentro del CSV (28 filas, 27 IDs únicos).

## Restricciones inviolables

- **NO modificar tablas legacy** (`piv`, `modulo`, `averia`, `asignacion`, etc.). Solo INSERT en `lv_averia_icca`.
- **NO link automático** con `asignacion` legacy. Las averías ICCA y `asignacion` son sistemas distintos en este bloque. El link admin↔técnico (PWA cierre) llega en Bloques 12e/12g.
- **PHP 8.2 floor**, sin paquetes nuevos. CSV parser nativo PHP `fgetcsv` o `League\Csv` (ya disponible vía Laravel). Preferir nativo.
- **NO upload directo a `lv_averia_icca` sin transaction**: parse + dry-run preview + admin confirm + apply real, todo dentro de DB::transaction.
- **NO ejecutar** `php artisan migrate` ni tinker contra prod (`.env` LOCAL apunta a SiteGround prod). **Solo `php artisan test`** (SQLite memory).
- **DESIGN.md Carbon** obligatorio: badges color por categoría, `data-mono` en columnas técnicas (sgip_id, panel_id_sgip), ColorColumn para activa boolean.
- **Slug explícito** `lv_averia_icca` Resource: `'averias-icca'` (Bloque 08b pluralizer defense).
- **Tests Pest verde**. Suite actual 306 → ≥321 verde.
- **CI 3/3 verde**.
- **Pint clean**.

## Plan de cambios

### Step 1 — Migration `2026_05_06_000000_create_lv_averia_icca_table.php`

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
        Schema::create('lv_averia_icca', function (Blueprint $t) {
            $t->id();
            $t->string('sgip_id', 20)->unique()->comment('Id del CSV SGIP, ej "0028078"');
            $t->string('panel_id_sgip', 20)->comment('Resumen CSV raw, ej "PANEL 18484"');
            $t->unsignedInteger('piv_id')->nullable()->comment('Match resolved con piv.parada_cod, NULL si ambiguo o no-match');
            $t->string('categoria', 80)->comment('Problemas de comunicación / Panel apagado / Problema de tiempos / Problema de audio / otras');
            $t->text('descripcion')->nullable();
            $t->mediumText('notas')->nullable()->comment('Hilo histórico CAU_ICCA/SGIP_winfin/SGIP_Indra con timestamps');
            $t->string('estado_externo', 30)->comment('Estado CSV, típicamente asignada');
            $t->string('asignada_a', 30)->comment('Asignada a CSV, típicamente SGIP_winfin');
            $t->boolean('activa')->default(true);
            $t->timestamp('fecha_import')->comment('Cuando admin subió este CSV');
            $t->string('archivo_origen', 255)->comment('Filename del CSV subido');
            $t->unsignedBigInteger('imported_by_user_id')->nullable();
            $t->timestamp('marked_inactive_at')->nullable()->comment('Cuando dejó de aparecer en CSV nuevo');
            $t->timestamps();

            $t->index(['piv_id', 'activa'], 'idx_piv_activa');
            $t->index('categoria', 'idx_categoria');
            $t->index(['activa', 'fecha_import'], 'idx_activa_fecha');

            $t->foreign('imported_by_user_id', 'fk_averia_icca_imported_by')
                ->references('id')->on('lv_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_averia_icca');
    }
};
```

### Step 2 — Modelo `App\Models\LvAveriaIcca`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LvAveriaIccaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LvAveriaIcca extends Model
{
    use HasFactory;

    protected $table = 'lv_averia_icca';

    public const CAT_COMUNICACION = 'Problemas de comunicación';
    public const CAT_APAGADO = 'Panel apagado';
    public const CAT_TIEMPOS = 'Problema de tiempos';
    public const CAT_AUDIO = 'Problema de audio';
    public const CAT_OTRAS = 'Otras';

    public const CATEGORIAS_CONOCIDAS = [
        self::CAT_COMUNICACION,
        self::CAT_APAGADO,
        self::CAT_TIEMPOS,
        self::CAT_AUDIO,
    ];

    protected $fillable = [
        'sgip_id', 'panel_id_sgip', 'piv_id', 'categoria',
        'descripcion', 'notas', 'estado_externo', 'asignada_a',
        'activa', 'fecha_import', 'archivo_origen',
        'imported_by_user_id', 'marked_inactive_at',
    ];

    protected $casts = [
        'piv_id' => 'integer',
        'activa' => 'boolean',
        'fecha_import' => 'datetime',
        'imported_by_user_id' => 'integer',
        'marked_inactive_at' => 'datetime',
    ];

    protected static function newFactory(): LvAveriaIccaFactory
    {
        return LvAveriaIccaFactory::new();
    }

    public function piv(): BelongsTo
    {
        return $this->belongsTo(Piv::class, 'piv_id', 'piv_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }

    public function scopeActivas(Builder $q): void
    {
        $q->where('activa', true);
    }

    public function scopeInactivas(Builder $q): void
    {
        $q->where('activa', false);
    }
}
```

### Step 3 — Reverse relation en `Piv` model

Añadir al modelo `app/Models/Piv.php`:

```php
public function averiasIcca(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(LvAveriaIcca::class, 'piv_id', 'piv_id');
}
```

### Step 4 — Factory `database/factories/LvAveriaIccaFactory.php`

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LvAveriaIcca;
use Illuminate\Database\Eloquent\Factories\Factory;

class LvAveriaIccaFactory extends Factory
{
    protected $model = LvAveriaIcca::class;

    public function definition(): array
    {
        return [
            'sgip_id' => str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 7, '0', STR_PAD_LEFT),
            'panel_id_sgip' => 'PANEL ' . $this->faker->unique()->numberBetween(10000, 99999),
            'piv_id' => null,
            'categoria' => $this->faker->randomElement(LvAveriaIcca::CATEGORIAS_CONOCIDAS),
            'descripcion' => $this->faker->sentence(),
            'notas' => null,
            'estado_externo' => 'asignada',
            'asignada_a' => 'SGIP_winfin',
            'activa' => true,
            'fecha_import' => now(),
            'archivo_origen' => 'SGIP_winfin_test.csv',
            'imported_by_user_id' => null,
            'marked_inactive_at' => null,
        ];
    }

    public function activa(): self { return $this->state(fn() => ['activa' => true, 'marked_inactive_at' => null]); }
    public function inactiva(): self { return $this->state(fn() => ['activa' => false, 'marked_inactive_at' => now()]); }
}
```

### Step 5 — Service `App\Services\AveriaIccaImportService`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LvAveriaIcca;
use App\Models\Piv;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Importa CSV SGIP exportado de ICCA. Política ADD + mark inactive como foto del momento.
 *
 * Cada upload se trata como snapshot Winfin completo:
 *   - Filas nuevas (sgip_id no existente) → INSERT con activa=true.
 *   - Filas existentes → UPDATE (refresca categoría, descripcion, notas, etc.).
 *   - Activas anteriores ausentes en CSV → activa=false + marked_inactive_at=now().
 */
final class AveriaIccaImportService
{
    public const COLUMNAS_OBLIGATORIAS = ['Id', 'Categoría', 'Resumen', 'Estado', 'Descripción', 'NOTAS', 'Asignada a'];

    /**
     * @return array{rows_parsed: int, would_create: int, would_update: int, would_mark_inactive: int, unmatched_panels: list<string>, ambiguous_panels: list<string>}
     */
    public function preview(UploadedFile $csv): array
    {
        $rows = $this->parseCsv($csv->getRealPath());
        $sgipIds = array_column($rows, 'sgip_id');

        $existing = LvAveriaIcca::query()
            ->whereIn('sgip_id', $sgipIds)
            ->pluck('sgip_id')->all();
        $existingSet = array_flip($existing);

        $wouldCreate = 0;
        $wouldUpdate = 0;
        $unmatched = [];
        $ambiguous = [];
        foreach ($rows as $r) {
            if (isset($existingSet[$r['sgip_id']])) {
                $wouldUpdate++;
            } else {
                $wouldCreate++;
            }
            $resolution = $this->resolvePivId($r['panel_id_sgip']);
            if ($resolution === 'unmatched') $unmatched[] = $r['panel_id_sgip'];
            elseif ($resolution === 'ambiguous') $ambiguous[] = $r['panel_id_sgip'];
        }

        $wouldMarkInactive = LvAveriaIcca::query()
            ->where('activa', true)
            ->whereNotIn('sgip_id', $sgipIds)
            ->count();

        return [
            'rows_parsed' => count($rows),
            'would_create' => $wouldCreate,
            'would_update' => $wouldUpdate,
            'would_mark_inactive' => $wouldMarkInactive,
            'unmatched_panels' => array_values(array_unique($unmatched)),
            'ambiguous_panels' => array_values(array_unique($ambiguous)),
        ];
    }

    /**
     * @return array{created: int, updated: int, marked_inactive: int, errors: list<string>}
     */
    public function import(UploadedFile $csv, User $admin): array
    {
        $filename = $csv->getClientOriginalName();
        $importTime = Carbon::now();

        $rows = $this->parseCsv($csv->getRealPath());
        $sgipIds = array_column($rows, 'sgip_id');

        return DB::transaction(function () use ($rows, $sgipIds, $admin, $filename, $importTime): array {
            $created = 0;
            $updated = 0;
            $errors = [];

            foreach ($rows as $r) {
                $pivId = $this->resolveOrNull($r['panel_id_sgip']);
                $payload = [
                    'panel_id_sgip' => $r['panel_id_sgip'],
                    'piv_id' => $pivId,
                    'categoria' => $r['categoria'],
                    'descripcion' => $r['descripcion'] ?: null,
                    'notas' => $r['notas'] ?: null,
                    'estado_externo' => $r['estado_externo'],
                    'asignada_a' => $r['asignada_a'],
                    'activa' => true,
                    'fecha_import' => $importTime,
                    'archivo_origen' => $filename,
                    'imported_by_user_id' => $admin->id,
                    'marked_inactive_at' => null,
                ];

                $existing = LvAveriaIcca::where('sgip_id', $r['sgip_id'])->first();
                if ($existing === null) {
                    LvAveriaIcca::create(array_merge(['sgip_id' => $r['sgip_id']], $payload));
                    $created++;
                } else {
                    $existing->update($payload);
                    $updated++;
                }
            }

            // Mark inactive las activas anteriores que no aparecen en este CSV.
            $markedInactive = LvAveriaIcca::query()
                ->where('activa', true)
                ->whereNotIn('sgip_id', $sgipIds)
                ->update(['activa' => false, 'marked_inactive_at' => $importTime]);

            return [
                'created' => $created,
                'updated' => $updated,
                'marked_inactive' => $markedInactive,
                'errors' => $errors,
            ];
        });
    }

    /**
     * @return list<array{sgip_id: string, panel_id_sgip: string, categoria: string, descripcion: string, notas: string, estado_externo: string, asignada_a: string}>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("No se pudo abrir el CSV: {$path}");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new \RuntimeException('CSV vacío o malformado');
        }

        $missing = array_diff(self::COLUMNAS_OBLIGATORIAS, $header);
        if ($missing !== []) {
            fclose($handle);
            throw new \RuntimeException('CSV missing columns: ' . implode(', ', $missing));
        }

        $idx = array_flip($header);
        $rows = [];
        while (($r = fgetcsv($handle)) !== false) {
            if (count($r) < count(self::COLUMNAS_OBLIGATORIAS)) continue;
            $rows[] = [
                'sgip_id' => trim($r[$idx['Id']]),
                'panel_id_sgip' => trim($r[$idx['Resumen']]),
                'categoria' => trim($r[$idx['Categoría']]),
                'descripcion' => trim($r[$idx['Descripción']]),
                'notas' => trim($r[$idx['NOTAS']]),
                'estado_externo' => trim($r[$idx['Estado']]),
                'asignada_a' => trim($r[$idx['Asignada a']]),
            ];
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Match heurístico panel SGIP → piv.parada_cod.
     * @return string|int 'unmatched', 'ambiguous', o piv_id integer
     */
    private function resolvePivId(string $panelIdSgip): string|int
    {
        if (! preg_match('/(\d+)/', $panelIdSgip, $m)) return 'unmatched';
        $num = $m[1];

        $exact = Piv::where('parada_cod', $num)->pluck('piv_id')->all();
        if (count($exact) === 1) return $exact[0];
        if (count($exact) > 1) return 'ambiguous';

        $byCast = Piv::whereRaw('CAST(parada_cod AS UNSIGNED) = ?', [(int) $num])
            ->pluck('piv_id')->all();
        if (count($byCast) === 1) return $byCast[0];
        if (count($byCast) > 1) return 'ambiguous';

        return 'unmatched';
    }

    private function resolveOrNull(string $panelIdSgip): ?int
    {
        $r = $this->resolvePivId($panelIdSgip);
        if (is_int($r)) return $r;
        Log::info("AveriaIccaImport: panel {$r} for {$panelIdSgip}");
        return null;
    }
}
```

### Step 6 — Filament Page custom de upload

`app/Filament/Pages/ImportarSgip.php` con form Livewire de FileUpload + botón Preview + modal Confirm.

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\AveriaIccaImportService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;

final class ImportarSgip extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Averías';

    protected static ?string $navigationLabel = 'Importar SGIP';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-up';

    protected static ?string $slug = 'importar-sgip';

    protected static string $view = 'filament.pages.importar-sgip';

    public ?array $data = [];

    public ?array $previewResult = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            FileUpload::make('csv')
                ->label('CSV SGIP exportado')
                ->acceptedFileTypes(['text/csv', 'application/csv', 'text/plain'])
                ->maxSize(5120)
                ->disk('local')
                ->directory('imports/sgip')
                ->preserveFilenames(false)
                ->required(),
        ])->statePath('data');
    }

    public function preview(): void
    {
        $path = $this->data['csv'] ?? null;
        if (! $path) {
            Notification::make()->title('Sube un CSV primero')->danger()->send();
            return;
        }
        $absPath = storage_path('app/' . $path);
        $upload = new UploadedFile($absPath, basename($absPath), 'text/csv', null, true);
        $this->previewResult = app(AveriaIccaImportService::class)->preview($upload);
    }

    public function confirm(): void
    {
        $path = $this->data['csv'] ?? null;
        if (! $path || ! $this->previewResult) {
            Notification::make()->title('Genera el preview primero')->danger()->send();
            return;
        }
        $absPath = storage_path('app/' . $path);
        $upload = new UploadedFile($absPath, basename($absPath), 'text/csv', null, true);
        $result = app(AveriaIccaImportService::class)->import($upload, auth()->user());

        Notification::make()
            ->title("Import OK")
            ->body("Created {$result['created']} · Updated {$result['updated']} · Marked inactive {$result['marked_inactive']}")
            ->success()
            ->send();

        $this->previewResult = null;
        $this->form->fill();
        $this->redirect(\App\Filament\Resources\LvAveriaIccaResource::getUrl('index'));
    }
}
```

Vista `resources/views/filament/pages/importar-sgip.blade.php` con:
- Form FileUpload.
- Botón "Generar preview".
- Si preview no null: tabla con `rows_parsed`, `would_create`, `would_update`, `would_mark_inactive`, listas `unmatched_panels`, `ambiguous_panels`.
- Modal de confirm grande: "Vas a marcar {{ would_mark_inactive }} averías como inactivas. Esto es la operativa esperada de foto-completa diaria. ¿Confirmar?".
- Botón confirm que dispara `confirm()`.

### Step 7 — Filament Resource `LvAveriaIccaResource` (read-only browse)

`app/Filament/Resources/LvAveriaIccaResource.php` con:
- Slug `averias-icca`.
- Nav group `Averías`, label `Averías ICCA`, sort 2.
- Badge nav: count `activas` totales.
- Tabla con columnas: badge sgip_id (mono), panel_id_sgip (mono), `piv.parada_cod` con `getStateUsing`+placeholder "—", categoria badge color, descripcion limit 60, activa boolean badge, fecha_import, importedBy.name.
- Filtros: SelectFilter categoria (4 valores fijos + "Otras"), TernaryFilter activa, Filter fecha_import range, SelectFilter ruta (join lv_piv_ruta_municipio similar a LvRevisionPendiente).
- Default sort `activa DESC, fecha_import DESC`.
- **NO actions inline** (read-only). View slideOver con infolist mostrando notas completas con monospace font.
- **NO Edit**, **NO Create**, **NO Bulk delete**. Solo Index + View slideOver.

### Step 8 — Console command (opcional CLI)

`lv:import-averia-icca {file}` para CLI. Útil futuro si automatizamos descarga ICCA.

```php
class ImportAveriaIcca extends Command
{
    protected $signature = 'lv:import-averia-icca {file : Path absoluto al CSV} {--user= : ID admin lv_users}';

    public function handle(AveriaIccaImportService $svc): int
    {
        $path = $this->argument('file');
        $userId = (int) ($this->option('user') ?? 1);
        $admin = User::findOrFail($userId);

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::INVALID;
        }

        $upload = new UploadedFile($path, basename($path), 'text/csv', null, true);
        $preview = $svc->preview($upload);
        $this->table(['Métrica', 'Valor'], collect($preview)->map(fn($v,$k)=>[$k, is_array($v)?count($v):$v])->values());

        if (! $this->confirm("Aplicar import (mark inactive {$preview['would_mark_inactive']})?")) {
            return self::SUCCESS;
        }

        $result = $svc->import($upload, $admin);
        $this->info("Created {$result['created']} · Updated {$result['updated']} · Marked inactive {$result['marked_inactive']}");
        return self::SUCCESS;
    }
}
```

### Step 9 — Tests Pest (~16-18 tests)

`tests/Unit/Services/AveriaIccaImportServiceTest.php`:
- Parse CSV con header correcto.
- Reject CSV con columnas faltantes.
- preview() count `rows_parsed`, `would_create`, `would_update`, `would_mark_inactive`.
- import() crea filas nuevas + actualiza existentes + marca inactivas las ausentes.
- Match piv_id exacto (`PANEL 18484` → `parada_cod='18484'`).
- Match piv_id via CAST numérico (`PANEL 07022` → `parada_cod='07022\t\t'`).
- Match ambiguous (panel + sufijo A) → piv_id NULL.
- Match no-match → piv_id NULL.
- Audit trail: fecha_import, archivo_origen, imported_by_user_id correctos.
- Idempotencia: re-import mismo CSV → 0 created, N updated, 0 marked_inactive.
- Transaction rollback si DB falla.

`tests/Feature/Filament/LvAveriaIccaResourceTest.php`:
- Admin accede /admin/averias-icca → 200.
- Non-admin → 403.
- Lista filas + filtros (categoria, activa, fecha range).
- Slug correcto.

`tests/Feature/Filament/ImportarSgipPageTest.php`:
- Upload CSV → preview muestra counts.
- Confirm → import + redirect a LvAveriaIccaResource.

`tests/Feature/Console/ImportAveriaIccaTest.php`:
- Comando con file válido + --user → import OK.
- Sin file → INVALID.

### Step 10 — Smoke local (text-only, NO contra prod)

```bash
php artisan test tests/Unit/Services/AveriaIccaImportServiceTest.php
php artisan test tests/Feature/Filament/LvAveriaIccaResourceTest.php
php artisan test tests/Feature/Filament/ImportarSgipPageTest.php
php artisan test tests/Feature/Console/ImportAveriaIccaTest.php
php artisan test
./vendor/bin/pint --test
```

**NO ejecutar** `php artisan migrate` ni tinker contra prod (`.env` LOCAL apunta SiteGround).

## DoD

- [ ] Migration `lv_averia_icca` con UNIQUE sgip_id, 3 indexes, FK lógica imported_by_user_id, columnas exactas del prompt.
- [ ] Modelo `LvAveriaIcca` con 5 categorías constantes, scopes `activas`/`inactivas`, relación `piv()` y `importedBy()`.
- [ ] `Piv::averiasIcca()` HasMany.
- [ ] Factory + state `activa`/`inactiva`.
- [ ] Service `AveriaIccaImportService` con `preview()` + `import()` + `parseCsv()` + `resolvePivId()` (exacto → CAST → ambiguous/unmatched).
- [ ] Page `ImportarSgip` con FileUpload + preview + modal confirm + apply + redirect.
- [ ] Resource `LvAveriaIccaResource` read-only con slug `averias-icca`, nav group `Averías`, badge nav count activas, filtros (categoria, activa, fecha, ruta).
- [ ] Console command `lv:import-averia-icca`.
- [ ] ~16-18 tests Pest verde. Suite total 306 → ≥321 verde.
- [ ] CI 3/3 verde.
- [ ] Pint clean.
- [ ] Smoke local OK con CSV de prueba (factory genera CSV temporal con 3-5 filas mixtas).
- [ ] **NO ejecutar contra prod** durante desarrollo.

## Smoke real obligatorio post-merge (sesión dedicada)

1. Backup fresh prod cifrado (runbook nuevo `docs/runbooks/backups/2026-05-XX-pre-bloque-12d.md`).
2. `migrate --pretend` → CREATE TABLE `lv_averia_icca`. Cero ALTER legacy.
3. `migrate --force`.
4. Login admin → Averías → "Importar SGIP".
5. Upload `/Users/winfin/Documents/LENOVO1/WINFIN PIVS/PIVCORE/Como Funcionamos/SGIP_winfin.csv`.
6. Click "Generar preview" → debe mostrar:
   - rows_parsed: 28
   - would_create: 28 (primera vez, todas nuevas)
   - would_update: 0
   - would_mark_inactive: 0 (BD vacía pre-import)
   - ambiguous_panels: lista paneles con sufijos A/B (probable 3 IDs).
   - unmatched_panels: lista paneles del SGIP no en `piv` BD.
7. Confirm → import. Notification "Created 28, Updated 0, Marked inactive 0".
8. Redirect a `/admin/averias-icca` → tabla con 28 filas, filter activa=Sí default, badges categoria coloreados, sgip_id en Plex Mono.
9. Filter Categoría = "Panel apagado" → 9 filas.
10. View slideOver de la avería con notas más largas (PANEL 10186, ~1492 chars de hilo histórico) → renderiza correcto con monospace.
11. **Test mark inactive**: editar el CSV local quitando 2 filas + re-upload → preview debe decir would_mark_inactive=2. Confirm → BD final tiene 28 activas-2=26 + 2 inactive con `marked_inactive_at` set.
12. Cleanup: borrar las 2 filas marcadas inactive (DELETE) + restaurar las 28 originales (re-import CSV completo).

## Riesgos y decisiones diferidas

1. **Modo "additive"**: NO incluido. Si admin quiere preservar averías ya activas que no aparecen en CSV partial, hay que añadir flag `--mode=additive` futuro. Decisión cerrada: solo full-snapshot por ahora.
2. **CSV columnas con drift**: si SGIP cambia columnas, parseCsv lanza excepción clara. No degrada silenciosamente.
3. **NOTAS muy largas**: usamos `mediumText` (16MB max). Suficiente para hilos históricos.
4. **Sufijos A/B**: en este bloque se marca como ambiguous con NULL. Bloque futuro 12d-bis podría: ofrecer UI admin para resolver ambiguous a mano (escoger panel A o B).
5. **Encoding**: el CSV está en UTF-8 (verificado). Si en el futuro SGIP exporta latin1, parseCsv debe detectar BOM o preguntar al admin. Por ahora UTF-8 hardcoded.
6. **Performance**: 28 filas hoy, escalará. Si en el futuro hay 1000+ averías, considerar chunked import + queue.
7. **Visualización notas hilo histórico**: el slideOver muestra notas con monospace para preservar timestamps + separadores `---`. Si admin quiere parsing del hilo, refactor futuro.

## REPORTE FINAL (formato esperado)

```
## Bloque 12d — REPORTE FINAL

### Estado
- Branch: bloque-12d-import-csv-sgip-icca
- Commits: N
- Tests: 306 → ~322 verde
- CI: 3/3 verde
- Pint: clean
- Smoke local: tests + suite

### Decisiones aplicadas
- Schema lv_averia_icca con UNIQUE sgip_id + audit trail completo.
- ADD + mark inactive + foto-completa.
- Match exacto → CAST → ambiguous/unmatched NULL.
- UI: Page Importar SGIP + Resource read-only browse.

### Pivots respecto al prompt
- (si los hubo, listar y justificar)
```

---

## Aplicación checklist obligatoria

| Sección | Aplicado | Cómo |
|---|---|---|
| 1. Compatibilidad framework | ✓ | Filament Page + Resource + Service + Console + Migration estándar Laravel. Slug explícito (08b). NO RelationManager en ViewRecord (08g/h, no aplica). |
| 2. Inferir de app vieja | N/A | App vieja no tiene importación SGIP. CSV real de la responsable es ground truth. |
| 3. Smoke real obligatorio | ✓ | Smoke prod con CSV real de 28 averías. Verificar match piv (incluye ambiguous A/B), preview accuracy, mark inactive funciona en re-upload. |
| 4. Test pivots | ✓ | Tests con CSV temp factory + asserts BD reales. Si Copilot pivota a tests del modelo, banderazo. |
| 5. Datos prod-shaped | ✓ | Tests con CSV con: filas con NOTAS largas, panel con sufijo A/B match, panel sin match BD, categorías 4 valores reales. Coincide con CSV real. |
