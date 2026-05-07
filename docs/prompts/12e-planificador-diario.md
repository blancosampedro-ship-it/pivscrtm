# Bloque 12e — Planificador diario (cruce ICCA + preventivos + ruta optimizada)

## Contexto

Tras Bloques 12c (rutas oficiales WINFIN) + 12d (import CSV SGIP/ICCA) la app tiene las
dos mitades del módulo 12 listas:

- **Mitad correctiva**: 28 averías `lv_averia_icca` activas con `piv_id` resolved (26) o NULL ambiguous (2).
- **Mitad preventiva**: 484 `lv_revision_pendiente` para mayo 2026 con admin tomando decisiones día a día (verificada_remoto / requiere_visita+fecha / excepcion).

**Bloque 12e materializa la frase nuclear** de la responsable del contrato:

> "Cada día la app cruza las averías activas de ICCA con las rutas preventivas planificadas y propone una ruta optimizada por zona."

Una **page Filament read-only** que cruza esas dos mitades + carry overs pendientes y propone una ruta del día agrupada por las 5 rutas oficiales del Excel + ordenada por `km_desde_ciempozuelos`.

**12e NO persiste nada**. Solo calcula y muestra. La asignación al técnico (persistencia en `lv_ruta_dia`) llega en Bloque 12f.

## Decisiones cerradas con el usuario antes del prompt (5 + 2 matices)

1. **Endpoint UI**: Filament Page custom en sidebar **Planificación → "Planificador del día"**. Slug `planificador-dia`. Junto a "Decisiones del día" del Bloque 12b.4.
2. **Algoritmo**: agrupar por **ruta oficial WINFIN** (ROSA-NO / ROSA-E / VERDE / AZUL / AMARILLO + grupo "Sin ruta"). Dentro de cada ruta, ordenar por **`km_desde_ciempozuelos` ASC** (más cercanos primero). Sin lat/lng no hacemos TSP — el orden por km es el match conceptual del Excel del operador.
3. **Items incluidos** (cruce de 3 fuentes):
   - `lv_averia_icca` con `activa = true` (correctivos del día).
   - `lv_revision_pendiente` con `status = requiere_visita` AND `fecha_planificada = today` (preventivos del día).
   - `lv_revision_pendiente` con `status = pendiente` AND `carry_over_origen_id IS NOT NULL` (carry overs pendientes prioridad).
   - **Excluidos**: status `verificada_remoto` / `excepcion` / `completada` (ya satisfechos).
4. **Asignación técnico**: NO en este bloque. 12e es solo visualización. La persistencia y asignación se hace en Bloque 12f con `lv_ruta_dia` + `lv_ruta_dia_item`.
5. **3 cards de métricas** arriba de la lista:
   - Card 1: total items hoy (`correctivos + preventivos + carry_overs`).
   - Card 2: distribución por ruta (`ROSA-E: 5 · ROSA-NO: 3 · VERDE: 2 · AZUL: 1 · AMARILLO: 0 · Sin ruta: 12`).
   - Card 3: ambiguous count — averías ICCA con `piv_id = NULL` que no se pueden ubicar.

**Matiz crítico añadido por el usuario**:

> "El planificador no debe modificar datos. Debe ser 100% read-only: calcula, agrupa, ordena y muestra. Nada de crear asignaciones, cambiar estados ni tocar `lv_revision_pendiente` o `lv_averia_icca`."

> "El grupo 'Sin ruta' debe ir siempre al final, aunque tenga menos kilómetros o datos parciales, para no mezclarlo con rutas operativas reales."

## Restricciones inviolables

- **READ-ONLY ABSOLUTO**. El service y la page NO ejecutan UPDATE / INSERT / DELETE en ninguna tabla. Solo SELECT con eager loading. El service NO recibe `$admin` ni nada que sugiera autoría — es un `compute(Carbon $date)` puro.
- **NO modificar tablas legacy** (`piv`, `modulo`, `tecnico`, `averia`, `asignacion`, etc.).
- **NO modificar tablas `lv_*` existentes** (`lv_averia_icca`, `lv_revision_pendiente`, `lv_piv_ruta*`).
- **NO crear migrations**. Todo el schema necesario ya existe.
- **NO crear `lv_ruta_dia*`** todavía. Eso es 12f.
- **Grupo "Sin ruta" siempre al final** del array de grupos, aunque haya rutas con 0 items (las cuales también se muestran para que admin sepa que no hay nada en ROSA-NO ese día, por ejemplo).
- **PHP 8.2 floor**, sin paquetes nuevos.
- **NO ejecutar** `php artisan migrate` ni tinker contra prod (`.env` LOCAL apunta a SiteGround, lección 12b.3 / 12d aplicada).
- **DESIGN.md Carbon**: badges color por categoría/tipo, headers serif, mono-fonts en sgip_id/parada_cod/km. ActionsPosition AfterColumns si hay tabla (lección 12d UX). `$infolist` (no `$i`) si hay closures Filament Infolist (08c).
- **Slug explícito** `planificador-dia` (08b pluralizer defense).
- **Tests Pest verde**. Suite actual 323 → ≥336 verde tras este bloque.
- **CI 3/3 verde**, **Pint clean**.

## Plan de cambios

### Step 1 — Service `App\Services\PlanificadorDelDiaService`

`app/Services/PlanificadorDelDiaService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LvAveriaIcca;
use App\Models\LvRevisionPendiente;
use App\Models\PivRuta;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Planificador diario READ-ONLY.
 *
 * Cruza tres fuentes para el día solicitado:
 *  - Averías ICCA activas (lv_averia_icca.activa=true).
 *  - Preventivos requiere_visita con fecha_planificada=$date.
 *  - Carry overs pendientes (lv_revision_pendiente.status=pendiente con
 *    carry_over_origen_id NOT NULL).
 *
 * Agrupa por ruta oficial WINFIN (lv_piv_ruta) + grupo "Sin ruta" al final.
 * Dentro de cada ruta, ordena por km_desde_ciempozuelos ASC.
 *
 * NUNCA escribe en BD. Solo SELECT.
 */
final class PlanificadorDelDiaService
{
    public const TIPO_CORRECTIVO = 'correctivo';
    public const TIPO_PREVENTIVO = 'preventivo';
    public const TIPO_CARRY_OVER = 'carry_over';

    public const SIN_RUTA_CODIGO = 'SIN_RUTA';
    public const SIN_RUTA_NOMBRE = 'Sin ruta';

    /**
     * @return array{
     *   fecha: string,  // YYYY-MM-DD
     *   total_items: int,
     *   total_correctivos: int,
     *   total_preventivos: int,
     *   total_carry_overs: int,
     *   ambiguous_count: int,
     *   distribucion: array<string, int>,  // codigo → count, incluye SIN_RUTA
     *   grupos: list<array{
     *     ruta_codigo: string,
     *     ruta_nombre: string,
     *     ruta_color_hint: ?string,
     *     ruta_sort_order: int,  // 99 para SIN_RUTA al final
     *     items_count: int,
     *     items: list<array{
     *       tipo: string,  // correctivo | preventivo | carry_over
     *       lv_id: int,  // id de lv_averia_icca o lv_revision_pendiente
     *       sgip_id: ?string,
     *       panel_id_sgip: ?string,
     *       categoria: ?string,
     *       descripcion: ?string,
     *       piv_id: ?int,
     *       parada_cod: ?string,
     *       municipio_modulo_id: ?int,
     *       km_desde_ciempozuelos: ?int,
     *       fecha_planificada: ?string,  // YYYY-MM-DD
     *       carry_origen_periodo: ?string,  // YYYY-MM
     *       carry_origen_status: ?string,
     *     }>,
     *   }>,
     * }
     */
    public function computar(CarbonInterface $fecha): array
    {
        $target = CarbonImmutable::instance($fecha)
            ->setTimezone('Europe/Madrid')
            ->startOfDay();
        $targetDateStr = $target->format('Y-m-d');

        // 1. Cargar las 5 rutas oficiales con sus municipios + km.
        $rutas = PivRuta::query()->orderBy('sort_order')->get();
        $municipioToRuta = $this->buildMunicipioToRutaMap();

        // 2. Cargar las 3 fuentes de items.
        $averias = LvAveriaIcca::query()
            ->activas()
            ->with('piv:piv_id,parada_cod,municipio')
            ->get();

        $preventivos = LvRevisionPendiente::query()
            ->where('status', LvRevisionPendiente::STATUS_REQUIERE_VISITA)
            ->whereDate('fecha_planificada', $targetDateStr)
            ->with('piv:piv_id,parada_cod,municipio')
            ->get();

        $carryOvers = LvRevisionPendiente::query()
            ->where('status', LvRevisionPendiente::STATUS_PENDIENTE)
            ->whereNotNull('carry_over_origen_id')
            ->with(['piv:piv_id,parada_cod,municipio', 'carryOverOrigen'])
            ->get();

        // 3. Convertir a items uniformes con metadata de ruta.
        $items = collect();
        $ambiguousCount = 0;

        foreach ($averias as $a) {
            if ($a->piv_id === null) {
                $ambiguousCount++;
            }
            $items->push($this->normalizeAveriaIcca($a, $municipioToRuta));
        }

        foreach ($preventivos as $p) {
            $items->push($this->normalizePreventivo($p, $municipioToRuta));
        }

        foreach ($carryOvers as $c) {
            $items->push($this->normalizeCarryOver($c, $municipioToRuta));
        }

        // 4. Agrupar por ruta_codigo + ordenar items dentro de cada grupo por km ASC.
        $grupos = [];
        foreach ($rutas as $ruta) {
            $rutaItems = $items->where('ruta_codigo', $ruta->codigo)->values();
            $grupos[] = [
                'ruta_codigo' => $ruta->codigo,
                'ruta_nombre' => $ruta->nombre,
                'ruta_color_hint' => $ruta->color_hint,
                'ruta_sort_order' => (int) $ruta->sort_order,
                'items_count' => $rutaItems->count(),
                'items' => $this->sortByKm($rutaItems)->all(),
            ];
        }

        // 5. Grupo SIN_RUTA siempre al final (sort_order 99).
        $sinRutaItems = $items->where('ruta_codigo', self::SIN_RUTA_CODIGO)->values();
        $grupos[] = [
            'ruta_codigo' => self::SIN_RUTA_CODIGO,
            'ruta_nombre' => self::SIN_RUTA_NOMBRE,
            'ruta_color_hint' => null,
            'ruta_sort_order' => 99,
            'items_count' => $sinRutaItems->count(),
            'items' => $this->sortByKm($sinRutaItems)->all(),
        ];

        // Distribución para card 2.
        $distribucion = [];
        foreach ($grupos as $g) {
            $distribucion[$g['ruta_codigo']] = $g['items_count'];
        }

        return [
            'fecha' => $targetDateStr,
            'total_items' => $items->count(),
            'total_correctivos' => $items->where('tipo', self::TIPO_CORRECTIVO)->count(),
            'total_preventivos' => $items->where('tipo', self::TIPO_PREVENTIVO)->count(),
            'total_carry_overs' => $items->where('tipo', self::TIPO_CARRY_OVER)->count(),
            'ambiguous_count' => $ambiguousCount,
            'distribucion' => $distribucion,
            'grupos' => $grupos,
        ];
    }

    /**
     * Mapeo municipio_modulo_id → ['ruta_codigo' => ..., 'km' => ..., 'ruta_nombre' => ...].
     * Una sola query JOIN, cached estático en la instancia.
     *
     * @return array<int, array{ruta_codigo: string, ruta_nombre: string, km: ?int}>
     */
    private function buildMunicipioToRutaMap(): array
    {
        return DB::table('lv_piv_ruta_municipio as rm')
            ->join('lv_piv_ruta as r', 'r.id', '=', 'rm.ruta_id')
            ->select('rm.municipio_modulo_id', 'r.codigo', 'r.nombre', 'rm.km_desde_ciempozuelos')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->municipio_modulo_id => [
                    'ruta_codigo' => $row->codigo,
                    'ruta_nombre' => $row->nombre,
                    'km' => $row->km_desde_ciempozuelos !== null ? (int) $row->km_desde_ciempozuelos : null,
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, array{ruta_codigo: string, ruta_nombre: string, km: ?int}>  $municipioToRuta
     * @return array<string, mixed>
     */
    private function normalizeAveriaIcca(LvAveriaIcca $a, array $municipioToRuta): array
    {
        $municipioId = $a->piv?->municipio !== null ? (int) $a->piv->municipio : null;
        $rutaInfo = $municipioId !== null ? ($municipioToRuta[$municipioId] ?? null) : null;

        return [
            'tipo' => self::TIPO_CORRECTIVO,
            'lv_id' => (int) $a->id,
            'sgip_id' => $a->sgip_id,
            'panel_id_sgip' => $a->panel_id_sgip,
            'categoria' => $a->categoria,
            'descripcion' => $a->descripcion,
            'piv_id' => $a->piv_id,
            'parada_cod' => $a->piv?->parada_cod ? trim((string) $a->piv->parada_cod) : null,
            'municipio_modulo_id' => $municipioId,
            'km_desde_ciempozuelos' => $rutaInfo['km'] ?? null,
            'ruta_codigo' => $rutaInfo['ruta_codigo'] ?? self::SIN_RUTA_CODIGO,
            'ruta_nombre' => $rutaInfo['ruta_nombre'] ?? self::SIN_RUTA_NOMBRE,
            'fecha_planificada' => null,
            'carry_origen_periodo' => null,
            'carry_origen_status' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $municipioToRuta
     * @return array<string, mixed>
     */
    private function normalizePreventivo(LvRevisionPendiente $p, array $municipioToRuta): array
    {
        return $this->normalizeRevisionRow($p, $municipioToRuta, self::TIPO_PREVENTIVO);
    }

    /**
     * @param  array<int, array<string, mixed>>  $municipioToRuta
     * @return array<string, mixed>
     */
    private function normalizeCarryOver(LvRevisionPendiente $c, array $municipioToRuta): array
    {
        $base = $this->normalizeRevisionRow($c, $municipioToRuta, self::TIPO_CARRY_OVER);
        $origen = $c->carryOverOrigen;
        if ($origen) {
            $base['carry_origen_periodo'] = sprintf('%04d-%02d', $origen->periodo_year, $origen->periodo_month);
            $base['carry_origen_status'] = $origen->status;
        }
        return $base;
    }

    /**
     * @param  array<int, array<string, mixed>>  $municipioToRuta
     * @return array<string, mixed>
     */
    private function normalizeRevisionRow(LvRevisionPendiente $r, array $municipioToRuta, string $tipo): array
    {
        $municipioId = $r->piv?->municipio !== null ? (int) $r->piv->municipio : null;
        $rutaInfo = $municipioId !== null ? ($municipioToRuta[$municipioId] ?? null) : null;

        return [
            'tipo' => $tipo,
            'lv_id' => (int) $r->id,
            'sgip_id' => null,
            'panel_id_sgip' => null,
            'categoria' => null,
            'descripcion' => $r->decision_notas,
            'piv_id' => $r->piv_id,
            'parada_cod' => $r->piv?->parada_cod ? trim((string) $r->piv->parada_cod) : null,
            'municipio_modulo_id' => $municipioId,
            'km_desde_ciempozuelos' => $rutaInfo['km'] ?? null,
            'ruta_codigo' => $rutaInfo['ruta_codigo'] ?? self::SIN_RUTA_CODIGO,
            'ruta_nombre' => $rutaInfo['ruta_nombre'] ?? self::SIN_RUTA_NOMBRE,
            'fecha_planificada' => $r->fecha_planificada?->format('Y-m-d'),
            'carry_origen_periodo' => null,
            'carry_origen_status' => null,
        ];
    }

    /**
     * Ordena items por km_desde_ciempozuelos ASC (NULL al final).
     */
    private function sortByKm(Collection $items): Collection
    {
        return $items->sort(function (array $a, array $b): int {
            $kmA = $a['km_desde_ciempozuelos'];
            $kmB = $b['km_desde_ciempozuelos'];
            if ($kmA === null && $kmB === null) return 0;
            if ($kmA === null) return 1;
            if ($kmB === null) return -1;
            return $kmA <=> $kmB;
        })->values();
    }
}
```

**Notas de implementación**:
- `computar()` recibe `CarbonInterface` y normaliza a Europe/Madrid + startOfDay.
- Eager loading explícito con SELECT específico (`piv:piv_id,parada_cod,municipio`) para evitar N+1.
- `buildMunicipioToRutaMap()`: 1 query JOIN, ~40 filas, evita N+1 con 28 averías + 484 preventivos + carry overs.
- Items con `piv.municipio` que no está en el mapping → grupo `SIN_RUTA`.
- Items con `piv_id NULL` (averías ambiguous): el `piv` relation viene NULL → mapping NULL → SIN_RUTA. Adicionalmente `ambiguous_count` los cuenta para card 3.
- Sort por km es estable (Collection sort preserva orden secundario).

### Step 2 — Filament Page `App\Filament\Pages\PlanificadorDelDia`

`app/Filament/Pages/PlanificadorDelDia.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\PlanificadorDelDiaService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

final class PlanificadorDelDia extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Planificación';

    protected static ?string $navigationLabel = 'Planificador del día';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $slug = 'planificador-dia';

    protected static string $view = 'filament.pages.planificador-del-dia';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $resultado = null;

    public function mount(): void
    {
        $this->form->fill([
            'fecha' => CarbonImmutable::now('Europe/Madrid')->format('Y-m-d'),
        ]);
        $this->recalcular();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('fecha')
                    ->label('Fecha')
                    ->required()
                    ->displayFormat('Y-m-d')
                    ->live()
                    ->afterStateUpdated(fn () => $this->recalcular()),
            ])
            ->statePath('data');
    }

    public function recalcular(): void
    {
        $state = $this->form->getState();
        $fecha = $state['fecha'] ?? CarbonImmutable::now('Europe/Madrid')->format('Y-m-d');

        $this->resultado = app(PlanificadorDelDiaService::class)
            ->computar(CarbonImmutable::parse($fecha, 'Europe/Madrid'));
    }
}
```

**Notas**:
- `mount()` rellena form con today Europe/Madrid + dispara `recalcular()` inicial.
- `live()` en DatePicker hace que cambiar fecha re-compute on-the-fly.
- `recalcular()` usa `$this->form->getState()` (lección 12d Filament getState).
- NO action botones (no se persiste nada). Solo selector fecha + visualización.

### Step 3 — Vista Blade `resources/views/filament/pages/planificador-del-dia.blade.php`

```blade
<x-filament-panels::page>
    <form class="mb-6">
        {{ $this->form }}
    </form>

    @if ($resultado)
        {{-- 3 cards de métricas --}}
        <div class="grid gap-4 md:grid-cols-3 mb-6">
            <x-filament::section>
                <x-slot name="heading">Total items hoy</x-slot>
                <div class="text-3xl font-semibold">{{ $resultado['total_items'] }}</div>
                <div class="text-sm text-gray-500">
                    {{ $resultado['total_correctivos'] }} correctivos ·
                    {{ $resultado['total_preventivos'] }} preventivos ·
                    {{ $resultado['total_carry_overs'] }} carry overs
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Distribución por ruta</x-slot>
                <div class="space-y-1 text-sm">
                    @foreach ($resultado['distribucion'] as $codigo => $count)
                        <div class="flex justify-between">
                            <span style="font-family: var(--font-mono, monospace);">{{ $codigo }}</span>
                            <span class="font-semibold">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Ambiguous</x-slot>
                <div class="text-3xl font-semibold">{{ $resultado['ambiguous_count'] }}</div>
                <div class="text-sm text-gray-500">averías ICCA sin piv_id resuelto (sufijos A/B sin desambiguar)</div>
            </x-filament::section>
        </div>

        {{-- Grupos por ruta --}}
        <div class="space-y-6">
            @foreach ($resultado['grupos'] as $grupo)
                <x-filament::section>
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            @if ($grupo['ruta_color_hint'])
                                <span class="inline-block w-3 h-3 rounded" style="background: {{ $grupo['ruta_color_hint'] }};"></span>
                            @endif
                            <span style="font-family: var(--font-mono, monospace);">{{ $grupo['ruta_codigo'] }}</span>
                            <span>· {{ $grupo['ruta_nombre'] }}</span>
                            <span class="text-gray-500 text-sm">({{ $grupo['items_count'] }} items)</span>
                        </span>
                    </x-slot>

                    @if ($grupo['items_count'] === 0)
                        <p class="text-sm text-gray-500">Sin items para esta ruta el día seleccionado.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="text-left text-gray-500">
                                    <tr>
                                        <th class="py-1 pr-2">Tipo</th>
                                        <th class="py-1 pr-2">Panel</th>
                                        <th class="py-1 pr-2">Municipio</th>
                                        <th class="py-1 pr-2">Km</th>
                                        <th class="py-1 pr-2">Detalle</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($grupo['items'] as $item)
                                        <tr class="border-t">
                                            <td class="py-2 pr-2">
                                                @if ($item['tipo'] === 'correctivo')
                                                    <span class="inline-flex items-center gap-1 rounded bg-red-100 text-red-800 px-2 py-0.5 text-xs">🔧 Correctivo</span>
                                                @elseif ($item['tipo'] === 'preventivo')
                                                    <span class="inline-flex items-center gap-1 rounded bg-blue-100 text-blue-800 px-2 py-0.5 text-xs">🔄 Preventivo</span>
                                                @else
                                                    <span class="inline-flex items-center gap-1 rounded bg-amber-100 text-amber-800 px-2 py-0.5 text-xs">⚠️ Carry over</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pr-2" style="font-family: var(--font-mono, monospace);">
                                                {{ $item['parada_cod'] ?? '—' }}
                                                @if ($item['piv_id'] === null)
                                                    <span class="text-xs text-amber-600 ml-1">(sin piv)</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pr-2">
                                                @if ($item['municipio_modulo_id'])
                                                    {{ $item['municipio_modulo_id'] }}
                                                @endif
                                            </td>
                                            <td class="py-2 pr-2" style="font-family: var(--font-mono, monospace);">
                                                {{ $item['km_desde_ciempozuelos'] !== null ? $item['km_desde_ciempozuelos'].' km' : '—' }}
                                            </td>
                                            <td class="py-2 pr-2 text-gray-700">
                                                @if ($item['categoria'])
                                                    <strong>{{ $item['categoria'] }}</strong>
                                                @endif
                                                @if ($item['fecha_planificada'])
                                                    <span style="font-family: var(--font-mono, monospace);">· {{ $item['fecha_planificada'] }}</span>
                                                @endif
                                                @if ($item['carry_origen_periodo'])
                                                    <span class="text-xs text-amber-600">· desde {{ $item['carry_origen_periodo'] }}</span>
                                                @endif
                                                @if ($item['descripcion'])
                                                    <span class="block text-xs text-gray-500 truncate max-w-md">{{ $item['descripcion'] }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-filament::section>
            @endforeach
        </div>
    @else
        <p class="text-gray-500">Selecciona una fecha para calcular el planificador.</p>
    @endif
</x-filament-panels::page>
```

**Notas vista**:
- 3 cards en `grid md:grid-cols-3` arriba.
- Grupos en `space-y-6` después.
- Cada grupo es una `<x-filament::section>` con header (color swatch + código mono + nombre + count).
- Tabla simple HTML por grupo. NO Filament Table component (innecesario para read-only sin paginación / filtros).
- Iconos emoji simples (🔧 🔄 ⚠️) — coherente con el patrón Carbon DESIGN.md.

### Step 4 — Tests Pest

#### 4.1 — `tests/Unit/Services/PlanificadorDelDiaServiceTest.php`

```php
<?php

declare(strict_types=1);

use App\Models\LvAveriaIcca;
use App\Models\LvRevisionPendiente;
use App\Models\Modulo;
use App\Models\Piv;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use App\Services\PlanificadorDelDiaService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->svc = new PlanificadorDelDiaService();

    // Setup base: 2 rutas + 2 municipios + 2 paneles.
    $this->mun_alcala = Modulo::factory()->municipio()->create(['nombre' => 'Alcalá']);
    $this->mun_aranjuez = Modulo::factory()->municipio()->create(['nombre' => 'Aranjuez']);

    $this->ruta_henares = PivRuta::factory()->create([
        'codigo' => 'ROSA-E', 'nombre' => 'Rosa Este', 'sort_order' => 2,
    ]);
    $this->ruta_sureste = PivRuta::factory()->create([
        'codigo' => 'AMARILLO', 'nombre' => 'Amarillo Sureste', 'sort_order' => 5,
    ]);

    PivRutaMunicipio::factory()->create([
        'ruta_id' => $this->ruta_henares->id,
        'municipio_modulo_id' => $this->mun_alcala->modulo_id,
        'km_desde_ciempozuelos' => 55,
    ]);
    PivRutaMunicipio::factory()->create([
        'ruta_id' => $this->ruta_sureste->id,
        'municipio_modulo_id' => $this->mun_aranjuez->modulo_id,
        'km_desde_ciempozuelos' => 18,
    ]);

    $this->piv_alcala = Piv::factory()->create(['parada_cod' => '12345', 'municipio' => (string) $this->mun_alcala->modulo_id]);
    $this->piv_aranjuez = Piv::factory()->create(['parada_cod' => '67890', 'municipio' => (string) $this->mun_aranjuez->modulo_id]);
});

it('return shape: fecha + totales + 3 fuentes + ambiguous + distribucion + grupos', function () {
    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($r)->toHaveKeys([
        'fecha', 'total_items', 'total_correctivos', 'total_preventivos',
        'total_carry_overs', 'ambiguous_count', 'distribucion', 'grupos',
    ]);
    expect($r['fecha'])->toBe('2026-05-06');
});

it('agrupa correctivos por ruta del piv', function () {
    LvAveriaIcca::factory()->create([
        'piv_id' => $this->piv_alcala->piv_id,
        'panel_id_sgip' => 'PANEL 12345 ALCALÁ',
        'categoria' => 'Problemas de comunicación',
    ]);

    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($r['total_correctivos'])->toBe(1);
    expect($r['total_items'])->toBe(1);

    $rosaE = collect($r['grupos'])->firstWhere('ruta_codigo', 'ROSA-E');
    expect($rosaE['items_count'])->toBe(1);
    expect($rosaE['items'][0]['tipo'])->toBe('correctivo');
    expect($rosaE['items'][0]['piv_id'])->toBe($this->piv_alcala->piv_id);
    expect($rosaE['items'][0]['km_desde_ciempozuelos'])->toBe(55);
});

it('preventivos requiere_visita today aparecen en grupo correcto', function () {
    LvRevisionPendiente::factory()->requiereVisita()->create([
        'piv_id' => $this->piv_aranjuez->piv_id,
        'fecha_planificada' => '2026-05-06',
    ]);

    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($r['total_preventivos'])->toBe(1);
    $amarillo = collect($r['grupos'])->firstWhere('ruta_codigo', 'AMARILLO');
    expect($amarillo['items_count'])->toBe(1);
    expect($amarillo['items'][0]['tipo'])->toBe('preventivo');
    expect($amarillo['items'][0]['km_desde_ciempozuelos'])->toBe(18);
});

it('preventivos con fecha distinta NO aparecen', function () {
    LvRevisionPendiente::factory()->requiereVisita()->create([
        'piv_id' => $this->piv_alcala->piv_id,
        'fecha_planificada' => '2026-05-10',
    ]);

    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($r['total_preventivos'])->toBe(0);
});

it('carry overs pendientes aparecen con periodo origen', function () {
    $origen = LvRevisionPendiente::factory()->pendiente()->create([
        'piv_id' => $this->piv_alcala->piv_id,
        'periodo_year' => 2026, 'periodo_month' => 4,
    ]);
    LvRevisionPendiente::factory()->pendiente()->create([
        'piv_id' => $this->piv_alcala->piv_id,
        'periodo_year' => 2026, 'periodo_month' => 5,
        'carry_over_origen_id' => $origen->id,
    ]);

    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($r['total_carry_overs'])->toBe(1);
    $rosaE = collect($r['grupos'])->firstWhere('ruta_codigo', 'ROSA-E');
    expect($rosaE['items'][0]['tipo'])->toBe('carry_over');
    expect($rosaE['items'][0]['carry_origen_periodo'])->toBe('2026-04');
});

it('excluye preventivos verificada_remoto excepcion completada', function () {
    LvRevisionPendiente::factory()->verificadaRemoto()->create([
        'piv_id' => $this->piv_alcala->piv_id, 'fecha_planificada' => '2026-05-06',
    ]);
    LvRevisionPendiente::factory()->excepcion()->create([
        'piv_id' => $this->piv_aranjuez->piv_id, 'fecha_planificada' => '2026-05-06',
    ]);
    LvRevisionPendiente::factory()->completada()->create([
        'piv_id' => $this->piv_alcala->piv_id, 'fecha_planificada' => '2026-05-06',
    ]);

    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($r['total_items'])->toBe(0);
});

it('items dentro de cada ruta ordenados por km ASC', function () {
    // 2 averías en ROSA-E: piv_alcala km=55, otro piv en ROSA-E km=40.
    $mun_otro = Modulo::factory()->municipio()->create(['nombre' => 'Loeches']);
    PivRutaMunicipio::factory()->create([
        'ruta_id' => $this->ruta_henares->id,
        'municipio_modulo_id' => $mun_otro->modulo_id,
        'km_desde_ciempozuelos' => 40,
    ]);
    $piv_loeches = Piv::factory()->create(['parada_cod' => 'XX', 'municipio' => (string) $mun_otro->modulo_id]);

    LvAveriaIcca::factory()->create(['piv_id' => $this->piv_alcala->piv_id]);  // km 55
    LvAveriaIcca::factory()->create(['piv_id' => $piv_loeches->piv_id]);  // km 40

    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    $rosaE = collect($r['grupos'])->firstWhere('ruta_codigo', 'ROSA-E');
    expect($rosaE['items'][0]['km_desde_ciempozuelos'])->toBe(40);
    expect($rosaE['items'][1]['km_desde_ciempozuelos'])->toBe(55);
});

it('paneles en municipio sin ruta asignada van a SIN_RUTA', function () {
    $mun_huerfano = Modulo::factory()->municipio()->create(['nombre' => 'Móstoles']);
    $piv_orphan = Piv::factory()->create(['parada_cod' => 'OR', 'municipio' => (string) $mun_huerfano->modulo_id]);
    LvAveriaIcca::factory()->create(['piv_id' => $piv_orphan->piv_id]);

    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    $sinRuta = collect($r['grupos'])->firstWhere('ruta_codigo', 'SIN_RUTA');
    expect($sinRuta['items_count'])->toBe(1);
});

it('SIN_RUTA siempre al final de grupos aunque tenga 0 items en otras rutas', function () {
    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    $codigos = collect($r['grupos'])->pluck('ruta_codigo')->all();
    expect(end($codigos))->toBe('SIN_RUTA');
});

it('averías con piv_id NULL cuentan en ambiguous_count y van a SIN_RUTA', function () {
    LvAveriaIcca::factory()->create([
        'piv_id' => null,
        'panel_id_sgip' => 'PANEL 17474B SAN SEBASTIAN DE LOS REYES',
    ]);

    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($r['ambiguous_count'])->toBe(1);
    expect($r['total_correctivos'])->toBe(1);

    $sinRuta = collect($r['grupos'])->firstWhere('ruta_codigo', 'SIN_RUTA');
    expect($sinRuta['items_count'])->toBe(1);
});

it('averías inactivas NO aparecen', function () {
    LvAveriaIcca::factory()->inactiva()->create(['piv_id' => $this->piv_alcala->piv_id]);

    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($r['total_correctivos'])->toBe(0);
});

it('mismo panel con avería + preventivo aparece dos veces', function () {
    LvAveriaIcca::factory()->create(['piv_id' => $this->piv_alcala->piv_id]);
    LvRevisionPendiente::factory()->requiereVisita()->create([
        'piv_id' => $this->piv_alcala->piv_id, 'fecha_planificada' => '2026-05-06',
    ]);

    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect($r['total_items'])->toBe(2);
    $rosaE = collect($r['grupos'])->firstWhere('ruta_codigo', 'ROSA-E');
    expect($rosaE['items_count'])->toBe(2);
});

it('servicio NO escribe en BD (read-only)', function () {
    LvAveriaIcca::factory()->create(['piv_id' => $this->piv_alcala->piv_id, 'updated_at' => now()->subDay()]);
    $beforeCount = LvAveriaIcca::count() + LvRevisionPendiente::count();

    $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    $afterCount = LvAveriaIcca::count() + LvRevisionPendiente::count();
    expect($afterCount)->toBe($beforeCount);
    // updated_at no debe haber cambiado.
    expect(LvAveriaIcca::first()->updated_at->lt(now()->subHour()))->toBeTrue();
});

it('distribucion incluye SIN_RUTA aunque sea 0', function () {
    $r = $this->svc->computar(CarbonImmutable::parse('2026-05-06', 'Europe/Madrid'));

    expect(array_keys($r['distribucion']))->toContain('SIN_RUTA');
    expect($r['distribucion']['SIN_RUTA'])->toBe(0);
});
```

#### 4.2 — `tests/Feature/Filament/PlanificadorDelDiaPageTest.php`

```php
<?php

declare(strict_types=1);

use App\Filament\Pages\PlanificadorDelDia;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('admin puede acceder a planificador-dia', function () {
    $this->get('/admin/planificador-dia')->assertOk();
});

it('non-admin no puede acceder', function () {
    $this->actingAs(User::factory()->tecnico()->create());
    $this->get('/admin/planificador-dia')->assertForbidden();
});

it('mount inicializa con today + computa resultado', function () {
    \Livewire\Livewire::test(PlanificadorDelDia::class)
        ->assertSet('data.fecha', \Carbon\CarbonImmutable::now('Europe/Madrid')->format('Y-m-d'))
        ->assertSeeText('Total items hoy');
});

it('cambio de fecha re-computa resultado', function () {
    \Livewire\Livewire::test(PlanificadorDelDia::class)
        ->set('data.fecha', '2026-05-10')
        ->assertSet('resultado.fecha', '2026-05-10');
});

it('slug explicito planificador-dia', function () {
    expect(PlanificadorDelDia::getSlug())->toBe('planificador-dia');
});

it('navegacion grupo Planificacion', function () {
    expect(PlanificadorDelDia::getNavigationGroup())->toBe('Planificación');
    expect(PlanificadorDelDia::getNavigationLabel())->toBe('Planificador del día');
});
```

### Step 5 — Smoke local (text-only)

```bash
php artisan test tests/Unit/Services/PlanificadorDelDiaServiceTest.php
php artisan test tests/Feature/Filament/PlanificadorDelDiaPageTest.php
php artisan test  # suite completa
./vendor/bin/pint --test
```

**NO ejecutar** `php artisan migrate` ni tinker contra prod (`.env` LOCAL apunta SiteGround, lección consolidada 12b.3 / 12c / 12d).

## DoD

- [ ] Service `PlanificadorDelDiaService::computar(Carbon $date)` con return shape exacto del docblock + 3 constantes TIPO_* + 2 constantes SIN_RUTA_*.
- [ ] Service NO escribe en BD (test específico cubre).
- [ ] Map municipio→ruta cached en 1 sola query JOIN.
- [ ] Page `PlanificadorDelDia` con slug `planificador-dia`, nav group "Planificación", DatePicker live + recalcular(), `$this->form->getState()` para sync (lección 12d).
- [ ] Vista Blade con 3 cards arriba + grupos por ruta + grupo SIN_RUTA al final + tabla por grupo con badges color por tipo.
- [ ] Tests Pest verde: ~13 service + ~6 page = ~19 tests. Suite total 323 → ≥336 verde.
- [ ] CI 3/3 verde.
- [ ] Pint clean.

## Smoke real obligatorio post-merge

Sub-bloque READ-ONLY → smoke real es **navegar la página con datos prod actuales**. Cero BD writes esperados.

1. Login admin → sidebar Planificación → "Planificador del día".
2. **Esperado en today (6 may 2026)**:
   - Total items: ~28 (todos correctivos ICCA importados, 0 preventivos requiere_visita today, 0 carry overs).
   - Card 2 distribución: ROSA-NO X · ROSA-E Y · VERDE Z · AZUL W · AMARILLO V · SIN_RUTA U (suma = 28).
   - Card 3 ambiguous: 2 (PANEL 17474B + PANEL 9079A).
   - Grupos visualizados con counts coherentes.
   - **SIN_RUTA al final** (la mayoría de las 28 averías ICCA están en municipios urbanos sin ruta — cobertura ~316 paneles "Sin ruta" del Bloque 12c).
3. Cambiar fecha a un día futuro → resultado se re-calcula → 0 items totales (no hay preventivos en futuro y averías ICCA aparecen siempre porque son "activas" sin filtro fecha).
4. Cambiar fecha a un día pasado → mismo resultado para averías. Para preventivos solo aparecerían los `requiere_visita` con `fecha_planificada` = ese día (ninguno en BD prod hoy).
5. Verificación BD post-smoke: `SELECT MAX(updated_at) FROM lv_averia_icca, lv_revision_pendiente` debe ser **anterior** al smoke (read-only validado).

**Sin cleanup** — no se crea/modifica nada.

## Riesgos y decisiones diferidas

1. **Performance**: con 28 averías + N preventivos + carry overs, el service hace 4 queries (rutas, mapping, averías, preventivos, carry). Escalable a miles sin problema.
2. **Lat/lng**: ordenar por km_desde_ciempozuelos es proxy. Si en el futuro hace falta TSP real, requiere Bloque 02f (geocoding) primero.
3. **Items "Sin ruta"**: aparecen al final con orden por km (NULL al final). Para esos items NULL km, orden interno es arbitrario (`Collection::sort` estable). Si admin quiere agruparlos por municipio, ajuste futuro.
4. **`piv` con sufijos A/B**: si una avería matchea con múltiples piv, en 12d quedó `piv_id NULL` (ambiguous). En 12e aparece en SIN_RUTA + cuenta en ambiguous_count. **Bloque 12d-bis futuro**: UI admin para resolver ambiguous a mano (escoger panel A o B).
5. **Carry overs sin fecha_planificada**: el carry over por definición no tiene fecha (es status=pendiente del mes nuevo). Todos los pendientes con carry_over_origen_id != NULL aparecen, independiente de la fecha del DatePicker. Decisión consciente: el carry over es **siempre prioridad arrastrada del mes anterior**, no se filtra por fecha.
6. **Concurrencia**: la page se calcula on-the-fly. Si admin tiene la pestaña abierta y otro proceso modifica BD (cron mensual día 1, import ICCA), el resultado en pantalla queda stale hasta refresh. Aceptable.

## REPORTE FINAL (formato esperado)

```
## Bloque 12e — REPORTE FINAL

### Estado
- Branch: bloque-12e-planificador-diario
- Commits: N
- Tests: 323 → ~336 verde
- CI: 3/3 verde
- Pint: clean
- Smoke local: tests + suite

### Decisiones aplicadas
- READ-ONLY estricto: 0 writes BD.
- Agrupación por ruta + km ASC.
- SIN_RUTA siempre al final.
- 3 cards métricas.
- Page custom + DatePicker live.

### Pivots respecto al prompt
- (si los hubo)
```

---

## Aplicación checklist obligatoria

| Sección | Aplicado | Cómo |
|---|---|---|
| 1. Compatibilidad framework | ✓ | Filament Page + Livewire form `live()` + getState() (lección 12d). Slug explícito (08b). NO RelationManager (08g/h, no aplica). |
| 2. Inferir de app vieja | N/A | Feature 100% nueva. Frase nuclear de la responsable materializada. |
| 3. Smoke real obligatorio | ✓ | Bloque READ-ONLY → smoke real es navegar página con prod data. Verificar updated_at no cambia. |
| 4. Test pivots = banderazo rojo | ✓ | Tests con factories realistas (Piv + ruta_municipio + LvAveriaIcca + LvRevisionPendiente). Test específico "servicio NO escribe en BD" como salvaguarda. |
| 5. Datos prod-shaped | ✓ | Tests cubren: averías ambiguas (piv_id NULL), municipio sin ruta (SIN_RUTA), carry over con periodo origen, mismo panel con avería+preventivo, fechas distintas. |
