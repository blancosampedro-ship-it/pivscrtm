# Bloque 10 — Dashboard + Reportes + Exports CSV (RGPD-safe)

## Contexto

Tras Bloque 09d (Carbon pivot, mergeado en `8b41a76` el 2 may 2026), la app tiene:

- 22 PRs mergeados desde Bloque 01.
- Sistema visual IBM Carbon establecido (DESIGN.md actualizado).
- AveriaResource accesible por URL pero **oculta del sidebar** (`shouldRegisterNavigation = false`).
- AsignacionResource visible bajo "Operaciones".
- 144 tests verde.

Bloque 10 entrega tres piezas independientes que cierran el "ciclo admin":

1. **Dashboard con widgets KPI** en `/admin` (página default Filament). Tres widgets: stats overview (4 stats), top paneles incidencia (lista), carga por técnico (lista).
2. **`TecnicoExportTransformer`** — clase que filtra los 7 campos RGPD sensibles del técnico. Implementa contrato dual: `forAdmin()` devuelve todo, `forOperador()` solo `nombre_completo`. Bloque 10 USA `forAdmin`; el transformer queda preparado y testeado para Bloque 12 (portal cliente).
3. **Grupo "Reportes" en sidebar + exports CSV** — re-registra `AveriaResource` bajo nuevo `navigationGroup = 'Reportes'` con filtros cross-panel + header action "Exportar CSV". Mismo header action "Exportar CSV" en `AsignacionResource`. CSV vía `streamDownload` síncrono.

Sidebar resultante:

```
Escritorio                ← Dashboard widgets (Bloque 10)
Operaciones
  └ Asignaciones          ← cola activa + botón Export CSV
Activos
  └ Paneles PIV
Reportes                  ← grupo nuevo
  └ Averías               ← cross-panel + filtros + Export CSV
```

## Restricciones inviolables que aplican

- **Regla #3 RGPD (CLAUDE.md):** los 7 campos sensibles del técnico (`dni`, `n_seguridad_social`, `ccc`, `telefono`, `direccion`, `email`, `carnet_conducir` — fuente: docblock de [Tecnico.php:15-17](app/Models/Tecnico.php)) **NUNCA pueden aparecer en `forOperador()`**. Solo `nombre_completo` está permitido. Si en el futuro se añade un campo nuevo a `Tecnico` que sea sensible, debe sumarse al blacklist.
- **Regla #11 (avería vs revisión):** los KPIs de asignaciones deben mostrar correctivo y revisión por separado, NO sumarlos. Misma regla cromática DESIGN.md §11.1 (Red 60 stripe correctivo, Green 50 stripe revisión) si se renderizan badges.
- **Bloque 07e (paneles archivados):** el widget "Top paneles incidencia" debe aplicar `Piv::scopeNotArchived()` — los 91 paneles bus archivados NO deben aparecer en el top.
- **DESIGN.md §11.4 IA parent-child:** averías siguen consultándose desde ViewPiv tabs para investigación per-panel. La nueva entrada en Reportes es **uso secundario para analytics cross-panel**. AveriaResource es ahora dual-context — actualizar §11.4 con un line edit.
- **NO romper tests existentes.** Los 144 tests deben seguir verdes.
- **NO PDF en este bloque.** CSV únicamente. Si en el futuro se necesita PDF → mini-bloque 10c.
- **NO Filament Export plugin async.** Single-tenant, single-admin, datos acotados (≤66k filas en averías) — CSV síncrono vía `response()->streamDownload(...)` es suficiente y evita queue worker en SiteGround.

## Plan de cambios

### Archivo nuevo — `app/Support/TecnicoExportTransformer.php`

Clase con dos métodos estáticos. Blacklist como constante para que sea fuente única de verdad y testable.

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tecnico;

/**
 * Transformer RGPD-safe para datos de técnico en exports.
 * Regla #3 (CLAUDE.md): los 7 campos sensibles NUNCA viajan al operador-cliente.
 *
 * Uso:
 *   - Admin (Bloque 10):     TecnicoExportTransformer::forAdmin($tecnico)
 *   - Operador (Bloque 12):  TecnicoExportTransformer::forOperador($tecnico)
 */
class TecnicoExportTransformer
{
    /**
     * Campos del técnico que NUNCA pueden viajar a un export al operador-cliente.
     * Fuente canónica: docblock de Tecnico.php + regla #3 CLAUDE.md.
     */
    public const BLACKLIST_FIELDS_FOR_OPERADOR = [
        'dni',
        'n_seguridad_social',
        'ccc',
        'telefono',
        'direccion',
        'email',
        'carnet_conducir',
    ];

    /**
     * Devuelve un array asociativo con TODOS los campos relevantes del técnico
     * para uso interno del admin. Incluye los sensibles RGPD porque el admin
     * legítimamente los necesita en su export (HR, payroll).
     *
     * @return array<string, mixed>
     */
    public static function forAdmin(?Tecnico $tecnico): array
    {
        if ($tecnico === null) {
            return self::emptyShape();
        }

        return [
            'tecnico_id'           => $tecnico->tecnico_id,
            'usuario'              => $tecnico->usuario,
            'nombre_completo'      => (string) $tecnico->nombre_completo,
            'email'                => $tecnico->email,
            'telefono'             => $tecnico->telefono,
            'dni'                  => $tecnico->dni,
            'n_seguridad_social'   => $tecnico->n_seguridad_social,
            'ccc'                  => $tecnico->ccc,
            'direccion'            => (string) $tecnico->direccion,
            'carnet_conducir'      => $tecnico->carnet_conducir,
            'status'               => $tecnico->status,
        ];
    }

    /**
     * Devuelve un array asociativo SOLO con los campos permitidos por RGPD
     * para exports al operador-cliente. El único dato del técnico que el
     * cliente puede ver es `nombre_completo`.
     *
     * @return array<string, mixed>
     */
    public static function forOperador(?Tecnico $tecnico): array
    {
        if ($tecnico === null) {
            return ['tecnico_nombre' => null];
        }

        return [
            'tecnico_nombre' => (string) $tecnico->nombre_completo,
        ];
    }

    /** @return array<string, null> */
    private static function emptyShape(): array
    {
        return array_fill_keys([
            'tecnico_id', 'usuario', 'nombre_completo', 'email', 'telefono',
            'dni', 'n_seguridad_social', 'ccc', 'direccion', 'carnet_conducir', 'status',
        ], null);
    }
}
```

Notas sobre el cast `Latin1String` de `nombre_completo` y `direccion`: al castearse a string con `(string) $tecnico->nombre_completo`, se convierte a UTF-8 limpio (cast existente). No hay que tocar nada del cast.

### Archivo nuevo — `app/Filament/Widgets/AsignacionesAveriasStatsOverview.php`

`StatsOverviewWidget` con 4 stats. Cada `Stat::make()` con descripción + color del DESIGN.md.

```php
<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Piv;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class AsignacionesAveriasStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Asignaciones abiertas — desglose por tipo (regla #11)
        $abiertas = Asignacion::where('status', 1);
        $abiertasCorrectivo = (clone $abiertas)->where('tipo', Asignacion::TIPO_CORRECTIVO)->count();
        $abiertasRevision   = (clone $abiertas)->where('tipo', Asignacion::TIPO_REVISION)->count();
        $abiertasTotal      = $abiertasCorrectivo + $abiertasRevision;

        // Averías del mes vs mes anterior
        $startMes      = Carbon::now()->startOfMonth();
        $startMesAnt   = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $endMesAnt     = Carbon::now()->subMonthNoOverflow()->endOfMonth();
        $averiasMes    = Averia::where('fecha', '>=', $startMes)->count();
        $averiasMesAnt = Averia::whereBetween('fecha', [$startMesAnt, $endMesAnt])->count();
        $delta         = $averiasMesAnt > 0
            ? (int) round((($averiasMes - $averiasMesAnt) / $averiasMesAnt) * 100)
            : null;
        $deltaLabel = $delta === null
            ? 'sin mes anterior comparable'
            : ($delta >= 0 ? "+{$delta}% vs mes anterior" : "{$delta}% vs mes anterior");

        // Paneles operativos / inactivos (excluye archivados — regla Bloque 07e)
        $operativos = Piv::notArchived()->where('status', 1)->count();
        $inactivos  = Piv::notArchived()->where('status', 0)->count();
        $totalActivo = $operativos + $inactivos;
        $pctOperativos = $totalActivo > 0
            ? (int) round(($operativos / $totalActivo) * 100)
            : 0;

        return [
            Stat::make('Asignaciones abiertas', (string) $abiertasTotal)
                ->description("{$abiertasCorrectivo} correctivas · {$abiertasRevision} revisiones")
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->color($abiertasTotal > 0 ? 'warning' : 'success'),

            Stat::make('Averías del mes', (string) $averiasMes)
                ->description($deltaLabel)
                ->descriptionIcon($delta !== null && $delta > 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($delta !== null && $delta > 20 ? 'danger' : 'gray'),

            Stat::make('Paneles operativos', "{$operativos} / {$totalActivo}")
                ->description("{$pctOperativos}% del total activo")
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Paneles inactivos', (string) $inactivos)
                ->description('averiados o sin operador')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($inactivos > 50 ? 'danger' : 'warning'),
        ];
    }
}
```

**Importante:** verificar que `Asignacion::TIPO_CORRECTIVO` y `TIPO_REVISION` existen como constantes (añadidas en Bloque 09 según status.md). Si no, usar `1` y `2` con un comentario explicando el mapping.

### Archivo nuevo — `app/Filament/Widgets/TopPanelesIncidenciaWidget.php`

`TableWidget` con top 5 paneles con más averías últimos 6 meses.

```php
<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Averia;
use App\Models\Piv;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class TopPanelesIncidenciaWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Top 5 paneles con más incidencias (6 meses)';

    protected int | string | array $columnSpan = 'full';

    protected function getTableQuery(): Builder
    {
        $sixMonthsAgo = Carbon::now()->subMonths(6);

        $topPivIds = Averia::where('fecha', '>=', $sixMonthsAgo)
            ->selectRaw('piv_id, COUNT(*) as incidencias_count')
            ->groupBy('piv_id')
            ->orderByDesc('incidencias_count')
            ->limit(5)
            ->pluck('incidencias_count', 'piv_id');

        return Piv::query()
            ->notArchived()
            ->whereIn('piv_id', $topPivIds->keys())
            ->withCount(['averias as incidencias_6m_count' => function (Builder $q) use ($sixMonthsAgo) {
                $q->where('fecha', '>=', $sixMonthsAgo);
            }])
            ->orderByDesc('incidencias_6m_count');
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('piv_id')
                ->label('ID')
                ->formatStateUsing(fn ($state) => '#'.str_pad((string) $state, 3, '0', STR_PAD_LEFT))
                ->extraAttributes(['data-mono' => true]),
            Tables\Columns\TextColumn::make('parada_cod')
                ->label('Parada')
                ->extraAttributes(['data-mono' => true]),
            Tables\Columns\TextColumn::make('direccion')
                ->label('Dirección')
                ->limit(40),
            Tables\Columns\TextColumn::make('municipioModulo.nombre')
                ->label('Municipio')
                ->default('—'),
            Tables\Columns\TextColumn::make('incidencias_6m_count')
                ->label('Averías 6m')
                ->badge()
                ->color('danger')
                ->extraAttributes(['data-mono' => true]),
        ];
    }

    protected function getTableRecordUrlUsing(): \Closure
    {
        return fn (Piv $record): string => \App\Filament\Resources\PivResource::getUrl('view', ['record' => $record]);
    }
}
```

### Archivo nuevo — `app/Filament/Widgets/CargaPorTecnicoWidget.php`

`TableWidget` con carga (asignaciones abiertas) por técnico activo.

```php
<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Tecnico;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class CargaPorTecnicoWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Carga por técnico (asignaciones abiertas)';

    protected int | string | array $columnSpan = 'full';

    protected function getTableQuery(): Builder
    {
        return Tecnico::query()
            ->where('status', 1) // técnicos activos solamente
            ->withCount([
                'asignaciones as abiertas_count' => fn (Builder $q) => $q->where('status', 1),
                'asignaciones as correctivos_count' => fn (Builder $q) => $q->where('status', 1)->where('tipo', 1),
                'asignaciones as revisiones_count' => fn (Builder $q) => $q->where('status', 1)->where('tipo', 2),
            ])
            ->orderByDesc('abiertas_count');
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('nombre_completo')
                ->label('Técnico'),
            Tables\Columns\TextColumn::make('abiertas_count')
                ->label('Total abiertas')
                ->badge()
                ->color(fn ($state) => $state > 5 ? 'danger' : ($state > 0 ? 'warning' : 'success'))
                ->extraAttributes(['data-mono' => true]),
            Tables\Columns\TextColumn::make('correctivos_count')
                ->label('Correctivos')
                ->extraAttributes(['data-mono' => true]),
            Tables\Columns\TextColumn::make('revisiones_count')
                ->label('Revisiones')
                ->extraAttributes(['data-mono' => true]),
        ];
    }
}
```

### Modificar — `app/Providers/Filament/AdminPanelProvider.php`

Añadir el array `->widgets([...])` con los 3 widgets nuevos. La línea actual `Widgets\AccountWidget::class` se conserva.

```php
->widgets([
    Widgets\AccountWidget::class,
    \App\Filament\Widgets\AsignacionesAveriasStatsOverview::class,
    \App\Filament\Widgets\TopPanelesIncidenciaWidget::class,
    \App\Filament\Widgets\CargaPorTecnicoWidget::class,
])
```

### Modificar — `app/Filament/Resources/AveriaResource.php`

**Cambios:**

1. Cambiar `protected static bool $shouldRegisterNavigation = false;` → `true`.
2. Cambiar `protected static ?string $navigationGroup = 'Operaciones';` → `'Reportes'`.
3. Cambiar `protected static ?int $navigationSort = 1;` → mantener o ajustar a `1` (único item del grupo Reportes).
4. En `getPages()` → conservar tal cual.
5. **Añadir header action "Exportar CSV"** en la List page del recurso. Esto va en `App\Filament\Resources\AveriaResource\Pages\ListAverias`. Si la clase no tiene `getHeaderActions()`, añadirlo:

```php
// app/Filament/Resources/AveriaResource/Pages/ListAverias.php

use App\Models\Averia;
use App\Support\TecnicoExportTransformer;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListAverias extends ListRecords
{
    protected static string $resource = AveriaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Exportar CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->action(fn () => $this->exportCsv()),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        // IMPORTANTE: respeta los filtros activos en la tabla.
        $query = $this->getFilteredTableQuery()
            ->with(['piv', 'asignacion.tecnico']);

        $filename = 'averias-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $output = fopen('php://output', 'w');
            // BOM UTF-8 para que Excel detecte encoding
            fwrite($output, "\xEF\xBB\xBF");

            // Header del CSV — incluye campos del técnico vía forAdmin (admin path).
            fputcsv($output, [
                'Avería ID', 'Panel ID', 'Parada', 'Fecha', 'Status',
                'Notas', 'Técnico ID', 'Técnico nombre', 'Técnico email',
                'Técnico DNI', 'Técnico NSS', 'Técnico CCC',
                'Técnico teléfono', 'Técnico dirección', 'Técnico carnet',
            ]);

            $query->lazy(500)->each(function (Averia $averia) use ($output) {
                $tecnico = TecnicoExportTransformer::forAdmin($averia->asignacion?->tecnico);
                fputcsv($output, [
                    $averia->averia_id,
                    $averia->piv_id,
                    $averia->piv?->parada_cod,
                    $averia->fecha?->format('Y-m-d H:i:s'),
                    $averia->status,
                    (string) $averia->notas,
                    $tecnico['tecnico_id'],
                    $tecnico['nombre_completo'],
                    $tecnico['email'],
                    $tecnico['dni'],
                    $tecnico['n_seguridad_social'],
                    $tecnico['ccc'],
                    $tecnico['telefono'],
                    $tecnico['direccion'],
                    $tecnico['carnet_conducir'],
                ]);
            });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
```

Verificar el método `getFilteredTableQuery()` — está disponible en `ListRecords` de Filament 3 y devuelve la query con los filtros UI aplicados. Si por algún motivo no está en la versión instalada, usar `$this->getTableQuery()` o reconstruir la query con `$this->tableFilters`.

### Modificar — `app/Filament/Resources/AsignacionResource/Pages/ListAsignaciones.php`

Mismo patrón header action "Exportar CSV", apuntando a Asignacion en lugar de Averia. Conservar todo lo demás.

```php
public function exportCsv(): StreamedResponse
{
    $query = $this->getFilteredTableQuery()
        ->with(['averia.piv', 'tecnico']);

    $filename = 'asignaciones-' . now()->format('Y-m-d') . '.csv';

    return response()->streamDownload(function () use ($query) {
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, [
            'Asignación ID', 'Avería ID', 'Panel ID', 'Tipo', 'Status',
            'Fecha asignación', 'Técnico ID', 'Técnico nombre', 'Técnico email',
            'Técnico DNI', 'Técnico NSS', 'Técnico CCC',
            'Técnico teléfono', 'Técnico dirección', 'Técnico carnet',
        ]);

        $query->lazy(500)->each(function ($asig) use ($output) {
            $tecnico = TecnicoExportTransformer::forAdmin($asig->tecnico);
            fputcsv($output, [
                $asig->asignacion_id,
                $asig->averia_id,
                $asig->averia?->piv_id,
                $asig->tipo === 1 ? 'Correctivo' : ($asig->tipo === 2 ? 'Revisión' : (string) $asig->tipo),
                $asig->status,
                $asig->fecha?->format('Y-m-d H:i:s'),
                $tecnico['tecnico_id'],
                $tecnico['nombre_completo'],
                $tecnico['email'],
                $tecnico['dni'],
                $tecnico['n_seguridad_social'],
                $tecnico['ccc'],
                $tecnico['telefono'],
                $tecnico['direccion'],
                $tecnico['carnet_conducir'],
            ]);
        });

        fclose($output);
    }, $filename, [
        'Content-Type' => 'text/csv; charset=UTF-8',
        'Cache-Control' => 'no-store',
    ]);
}
```

### Modificar — `DESIGN.md` §11.4 line edit

Sustituir la frase:

> `AveriaResource` queda **oculta del sidebar** (`shouldRegisterNavigation = false`) pero accesible por URL para deep-links.

Por:

> `AveriaResource` es **dual-context** desde Bloque 10: visible en sidebar bajo grupo "Reportes" para analytics cross-panel (filtros agregados por fecha/operador, exports CSV), Y accesible vía tab dentro de ViewPiv para investigación per-panel. La duplicación de navegación es intencional — dos contextos, dos rutas, mismo Resource.

Y añadir entrada en §12 Decisions Log:

> `| 2026-05-02 | AveriaResource pasa a dual-context: sidebar Reportes (analytics) + ViewPiv tab (investigación per-panel). Bloque 10. | Cross-panel reporting es uso secundario del admin (revisar averías por mes/operador, exportar CSV) — distinto a la investigación per-panel que mantiene el patrón parent-child. Dos contextos, dos rutas, mismo Resource. La duplicación es intencional y reutiliza el código de la tabla. |`

### Tests DoD — `tests/Feature/Bloque10*.php`

Crear estos archivos con los siguientes tests. Todos usan `RefreshDatabase`.

#### `tests/Feature/Bloque10ExportRgpdTest.php`

```php
it('tecnico_export_blacklist — forOperador strips all 7 sensitive fields', function () {
    $tecnico = Tecnico::factory()->create([
        'nombre_completo' => 'Juan Pérez',
        'dni' => '12345678A',
        'n_seguridad_social' => '281234567890',
        'ccc' => '281234567890',
        'telefono' => '600123456',
        'direccion' => 'Calle Falsa 123',
        'email' => 'juan@example.com',
        'carnet_conducir' => 'B12345678',
    ]);

    $exported = TecnicoExportTransformer::forOperador($tecnico);

    foreach (TecnicoExportTransformer::BLACKLIST_FIELDS_FOR_OPERADOR as $field) {
        expect($exported)->not->toHaveKey($field);
    }
});

it('tecnico_export_includes_nombre_completo — forOperador preserves nombre_completo', function () {
    $tecnico = Tecnico::factory()->create(['nombre_completo' => 'Juan Pérez']);
    $exported = TecnicoExportTransformer::forOperador($tecnico);
    expect($exported)->toHaveKey('tecnico_nombre');
    expect($exported['tecnico_nombre'])->toBe('Juan Pérez');
});

it('tecnico_export_admin_includes_all_fields — regression guard against blacklist leaking to admin path', function () {
    $tecnico = Tecnico::factory()->create([
        'nombre_completo' => 'Juan Pérez',
        'dni' => '12345678A',
        'email' => 'juan@example.com',
        'telefono' => '600123456',
    ]);

    $exported = TecnicoExportTransformer::forAdmin($tecnico);

    expect($exported)->toHaveKey('dni')->and($exported['dni'])->toBe('12345678A');
    expect($exported)->toHaveKey('email')->and($exported['email'])->toBe('juan@example.com');
    expect($exported)->toHaveKey('telefono')->and($exported['telefono'])->toBe('600123456');
    expect($exported)->toHaveKey('nombre_completo')->and($exported['nombre_completo'])->toBe('Juan Pérez');
});

it('tecnico_export_handles_null_tecnico — forOperador and forAdmin tolerate null', function () {
    $shapedOp = TecnicoExportTransformer::forOperador(null);
    expect($shapedOp)->toBeArray()->toHaveKey('tecnico_nombre');
    expect($shapedOp['tecnico_nombre'])->toBeNull();

    $shapedAdm = TecnicoExportTransformer::forAdmin(null);
    expect($shapedAdm)->toBeArray()->toHaveKey('nombre_completo');
});
```

#### `tests/Feature/Bloque10DashboardWidgetsTest.php`

```php
it('dashboard_asignaciones_abiertas_breakdown_by_tipo — stat correctivo only counts tipo=1', function () {
    // Seed: 3 correctivos abiertos (tipo=1, status=1) + 2 revisiones abiertas (tipo=2, status=1) + 1 cerrado
    $piv = Piv::factory()->create();
    foreach (range(1, 3) as $i) {
        $av = Averia::factory()->create(['piv_id' => $piv->piv_id]);
        Asignacion::factory()->create([
            'averia_id' => $av->averia_id, 'tipo' => 1, 'status' => 1,
        ]);
    }
    foreach (range(1, 2) as $i) {
        $av = Averia::factory()->create(['piv_id' => $piv->piv_id]);
        Asignacion::factory()->create([
            'averia_id' => $av->averia_id, 'tipo' => 2, 'status' => 1,
        ]);
    }
    $av = Averia::factory()->create(['piv_id' => $piv->piv_id]);
    Asignacion::factory()->create(['averia_id' => $av->averia_id, 'tipo' => 1, 'status' => 2]);

    Livewire::test(\App\Filament\Widgets\AsignacionesAveriasStatsOverview::class)
        ->assertSee('5')                       // total abiertas
        ->assertSee('3 correctivas · 2 revisiones');
});

it('dashboard_top_paneles_excludes_archived', function () {
    $pivOk = Piv::factory()->create(['piv_id' => 99001]);
    $pivArchived = Piv::factory()->create(['piv_id' => 99002]);

    // Generar 5 averías a cada panel, archivar el segundo
    foreach (range(1, 5) as $i) {
        Averia::factory()->create(['piv_id' => 99001, 'fecha' => now()->subWeek()]);
        Averia::factory()->create(['piv_id' => 99002, 'fecha' => now()->subWeek()]);
    }
    LvPivArchived::create([
        'piv_id' => 99002,
        'archived_at' => now(),
        'archived_by_user_id' => User::factory()->admin()->create()->id,
    ]);

    Livewire::test(\App\Filament\Widgets\TopPanelesIncidenciaWidget::class)
        ->assertCanSeeTableRecords([Piv::find(99001)])
        ->assertCanNotSeeTableRecords([Piv::find(99002)]);
});

it('dashboard_carga_por_tecnico_excludes_inactive_tecnicos', function () {
    $tecnicoActivo = Tecnico::factory()->create(['nombre_completo' => 'Pepe Activo', 'status' => 1]);
    $tecnicoInactivo = Tecnico::factory()->create(['nombre_completo' => 'Mario Inactivo', 'status' => 0]);

    $piv = Piv::factory()->create();
    $av1 = Averia::factory()->create(['piv_id' => $piv->piv_id]);
    Asignacion::factory()->create([
        'averia_id' => $av1->averia_id, 'tecnico_id' => $tecnicoActivo->tecnico_id, 'status' => 1,
    ]);
    $av2 = Averia::factory()->create(['piv_id' => $piv->piv_id]);
    Asignacion::factory()->create([
        'averia_id' => $av2->averia_id, 'tecnico_id' => $tecnicoInactivo->tecnico_id, 'status' => 1,
    ]);

    Livewire::test(\App\Filament\Widgets\CargaPorTecnicoWidget::class)
        ->assertCanSeeTableRecords([$tecnicoActivo])
        ->assertCanNotSeeTableRecords([$tecnicoInactivo]);
});
```

#### `tests/Feature/Bloque10ReportesIaTest.php`

```php
it('averia_resource_visible_in_sidebar_under_reportes', function () {
    expect(AveriaResource::shouldRegisterNavigation())->toBeTrue();
    expect(AveriaResource::getNavigationGroup())->toBe('Reportes');
});

it('averia_csv_export_includes_tecnico_fields_for_admin_path', function () {
    $admin = User::factory()->admin()->create();
    $tecnico = Tecnico::factory()->create([
        'nombre_completo' => 'Test Técnico',
        'dni' => '99999999X',
        'email' => 'test@tecnico.com',
    ]);
    $piv = Piv::factory()->create();
    $av = Averia::factory()->create(['piv_id' => $piv->piv_id]);
    Asignacion::factory()->create([
        'averia_id' => $av->averia_id, 'tecnico_id' => $tecnico->tecnico_id,
    ]);

    actingAs($admin);
    $response = Livewire::test(ListAverias::class)->callAction('export');

    // El test debe verificar que la respuesta es un StreamedResponse y contiene
    // los campos del técnico en el CSV (admin path = forAdmin → todos los campos).
    // Un patrón funcional: capturar el output en buffer.
    ob_start();
    $response->getEffects()['return']->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('Test Técnico');
    expect($csv)->toContain('99999999X');           // DNI presente en admin export (correcto)
    expect($csv)->toContain('test@tecnico.com');    // email presente
});

it('averia_csv_export_response_is_streamed', function () {
    $admin = User::factory()->admin()->create();
    actingAs($admin);

    $response = Livewire::test(ListAverias::class)->callAction('export');
    $returned = $response->getEffects()['return'] ?? null;

    expect($returned)->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class);
});
```

**Importante:** la API de Livewire para capturar la respuesta de una action en Filament puede variar. Si `getEffects()['return']` no funciona, usar el patrón alternativo:

```php
$response = $this->actingAs($admin)
    ->get(route('filament.admin.resources.averias.index'));

// Y luego dispararla via callAction o un endpoint custom.
```

Si hay quirks con la captura de StreamedResponse en tests, **no debilitar el test** — escalar al usuario explicando el problema. NO sustituir por un grep test que pierde la integración real.

## Verificación obligatoria antes del commit final

1. **Build:** `npm run build` → OK.
2. **Pint:** `vendor/bin/pint --test` → OK sobre los archivos PHP nuevos.
3. **Test suite completa:** `vendor/bin/pest` → **144 tests previos + ~10 nuevos = ~154 verde** (asegurar que ninguno previo se rompe).
4. **Servidor local:** `php artisan serve --host=127.0.0.1 --port=8000` background.
5. **Smoke HTTP:**
   - `curl -sI http://127.0.0.1:8000/up` → 200.
   - `curl -sI http://127.0.0.1:8000/admin/login` → 200.
   - `curl -sI http://127.0.0.1:8000/admin` (dashboard, redirige a login) → 302.
   - `curl -sI http://127.0.0.1:8000/admin/averias` (Reportes) → 200 ó 302.
6. **CI:** push → 3/3 verde (PHP 8.2, PHP 8.3, Vite build).

## Smoke real obligatorio (post-merge, a cargo del usuario)

Riesgo declarado: widgets renderizan dinámicamente vía Livewire — un bug de query o casteo se ve solo en navegador autenticado. El usuario debe:

1. **Dashboard `/admin`** (después de login):
   - 3 widgets visibles en orden: stats overview (4 stats arriba), top paneles incidencia, carga por técnico.
   - Stats: "Asignaciones abiertas" muestra desglose corr/rev. "Averías del mes" muestra delta vs mes anterior. "Paneles operativos" y "Inactivos" suman el total no-archivado.
   - Top paneles: 5 filas máximo, sin paneles archivados (bus IDs 99000+ del Bloque 07e), ordenados por count desc.
   - Carga por técnico: solo técnicos con `status=1` (3 activos).
2. **Sidebar:**
   - Nuevo grupo "Reportes" con item "Averías".
   - Click en "Reportes > Averías" → tabla cross-panel con todas las averías + filtros activos.
   - Click en "Operaciones > Asignaciones" → tabla cola activa.
3. **Export CSV — Reportes > Averías:**
   - Click botón "Exportar CSV" en el header.
   - Descarga `averias-{YYYY-MM-DD}.csv`.
   - Abrir en Excel/Numbers: encoding UTF-8 correcto (acentos OK), columnas 15, header presente.
   - Verificar que columnas técnico (DNI, email, etc.) están pobladas con datos del técnico.
4. **Export CSV — Operaciones > Asignaciones:**
   - Mismo patrón, archivo `asignaciones-{YYYY-MM-DD}.csv`.
5. **Filtros activos respetados en export:**
   - Filtrar Averías por status=1 → click "Exportar CSV" → CSV solo contiene averías con status=1.

## Definition of Done

- 1 PR (#24) con 3-4 commits coherentes:
  - `feat(support): add TecnicoExportTransformer with RGPD-safe forOperador path`
  - `feat(filament): add dashboard widgets — stats, top paneles, carga por tecnico`
  - `feat(filament): register AveriaResource under Reportes group with CSV export action`
  - `chore(filament): add CSV export action to AsignacionResource list page`
  - (opcional) `docs(design): mark AveriaResource as dual-context per Bloque 10 IA` — el line edit en DESIGN.md §11.4 + entrada Decisions Log §12.
- CI 3/3 verde.
- ~154 tests verde (144 + 10 nuevos aproximadamente).
- Working tree clean tras el push.
- PR review-ready (no draft).

## Reporte final que Copilot debe entregar

- SHAs de los commits.
- Diff resumen (archivos + líneas).
- Estado CI tras push.
- Confirmación HTTP de los 4 endpoints del smoke local.
- Nota explícita de cualquier pivot tomado:
  - ¿El `getFilteredTableQuery()` funcionó tal cual? Si hubo que reconstruir la query manualmente, decirlo.
  - ¿Las constantes `Asignacion::TIPO_CORRECTIVO` / `TIPO_REVISION` existían? Si no, decirlo.
  - ¿La captura de `StreamedResponse` en el test funcionó con `getEffects()['return']` o hubo que pivotar?
- Lista visual pendiente para el usuario (los 5 puntos de smoke real arriba).
