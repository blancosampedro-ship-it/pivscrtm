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
 * Cruza tres fuentes para el dia solicitado:
 *  - Averias ICCA activas (lv_averia_icca.activa=true).
 *  - Preventivos requiere_visita con fecha_planificada=$fecha.
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

    /** @var array<int, array{ruta_codigo: string, ruta_nombre: string, km: ?int}>|null */
    private ?array $municipioToRuta = null;

    /**
     * @return array{
     *   fecha: string,
     *   total_items: int,
     *   total_correctivos: int,
     *   total_preventivos: int,
     *   total_carry_overs: int,
     *   ambiguous_count: int,
     *   distribucion: array<string, int>,
     *   grupos: list<array{
     *     ruta_codigo: string,
     *     ruta_nombre: string,
     *     ruta_color_hint: ?string,
     *     ruta_sort_order: int,
     *     items_count: int,
     *     items: list<array{
     *       tipo: string,
     *       lv_id: int,
     *       sgip_id: ?string,
     *       panel_id_sgip: ?string,
     *       categoria: ?string,
     *       descripcion: ?string,
     *       piv_id: ?int,
     *       parada_cod: ?string,
     *       municipio_modulo_id: ?int,
     *       km_desde_ciempozuelos: ?int,
     *       fecha_planificada: ?string,
     *       carry_origen_periodo: ?string,
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

        $rutas = PivRuta::query()->orderBy('sort_order')->get();
        $municipioToRuta = $this->buildMunicipioToRutaMap();

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
            ->with(['piv:piv_id,parada_cod,municipio', 'carryOverOrigen:id,periodo_year,periodo_month,status'])
            ->get();

        $items = collect();
        $ambiguousCount = 0;

        foreach ($averias as $averia) {
            if ($averia->piv_id === null) {
                $ambiguousCount++;
            }

            $items->push($this->normalizeAveriaIcca($averia, $municipioToRuta));
        }

        foreach ($preventivos as $preventivo) {
            $items->push($this->normalizePreventivo($preventivo, $municipioToRuta));
        }

        foreach ($carryOvers as $carryOver) {
            $items->push($this->normalizeCarryOver($carryOver, $municipioToRuta));
        }

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

        $sinRutaItems = $items->where('ruta_codigo', self::SIN_RUTA_CODIGO)->values();
        $grupos[] = [
            'ruta_codigo' => self::SIN_RUTA_CODIGO,
            'ruta_nombre' => self::SIN_RUTA_NOMBRE,
            'ruta_color_hint' => null,
            'ruta_sort_order' => 99,
            'items_count' => $sinRutaItems->count(),
            'items' => $this->sortByKm($sinRutaItems)->all(),
        ];

        $distribucion = [];
        foreach ($grupos as $grupo) {
            $distribucion[$grupo['ruta_codigo']] = $grupo['items_count'];
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
     * @return array<int, array{ruta_codigo: string, ruta_nombre: string, km: ?int}>
     */
    private function buildMunicipioToRutaMap(): array
    {
        if ($this->municipioToRuta !== null) {
            return $this->municipioToRuta;
        }

        $this->municipioToRuta = DB::table('lv_piv_ruta_municipio as rm')
            ->join('lv_piv_ruta as r', 'r.id', '=', 'rm.ruta_id')
            ->select('rm.municipio_modulo_id', 'r.codigo', 'r.nombre', 'rm.km_desde_ciempozuelos')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->municipio_modulo_id => [
                    'ruta_codigo' => (string) $row->codigo,
                    'ruta_nombre' => (string) $row->nombre,
                    'km' => $row->km_desde_ciempozuelos !== null ? (int) $row->km_desde_ciempozuelos : null,
                ],
            ])
            ->all();

        return $this->municipioToRuta;
    }

    /**
     * @param  array<int, array{ruta_codigo: string, ruta_nombre: string, km: ?int}>  $municipioToRuta
     * @return array<string, mixed>
     */
    private function normalizeAveriaIcca(LvAveriaIcca $averia, array $municipioToRuta): array
    {
        $municipioId = $averia->piv?->municipio !== null ? (int) $averia->piv->municipio : null;
        $rutaInfo = $municipioId !== null ? ($municipioToRuta[$municipioId] ?? null) : null;

        return [
            'tipo' => self::TIPO_CORRECTIVO,
            'lv_id' => (int) $averia->id,
            'sgip_id' => $averia->sgip_id,
            'panel_id_sgip' => $averia->panel_id_sgip,
            'categoria' => $averia->categoria,
            'descripcion' => $averia->descripcion,
            'piv_id' => $averia->piv_id,
            'parada_cod' => $averia->piv?->parada_cod ? trim((string) $averia->piv->parada_cod) : null,
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
     * @param  array<int, array{ruta_codigo: string, ruta_nombre: string, km: ?int}>  $municipioToRuta
     * @return array<string, mixed>
     */
    private function normalizePreventivo(LvRevisionPendiente $preventivo, array $municipioToRuta): array
    {
        return $this->normalizeRevisionRow($preventivo, $municipioToRuta, self::TIPO_PREVENTIVO);
    }

    /**
     * @param  array<int, array{ruta_codigo: string, ruta_nombre: string, km: ?int}>  $municipioToRuta
     * @return array<string, mixed>
     */
    private function normalizeCarryOver(LvRevisionPendiente $carryOver, array $municipioToRuta): array
    {
        $base = $this->normalizeRevisionRow($carryOver, $municipioToRuta, self::TIPO_CARRY_OVER);
        $origen = $carryOver->carryOverOrigen;

        if ($origen !== null) {
            $base['carry_origen_periodo'] = sprintf('%04d-%02d', $origen->periodo_year, $origen->periodo_month);
            $base['carry_origen_status'] = $origen->status;
        }

        return $base;
    }

    /**
     * @param  array<int, array{ruta_codigo: string, ruta_nombre: string, km: ?int}>  $municipioToRuta
     * @return array<string, mixed>
     */
    private function normalizeRevisionRow(LvRevisionPendiente $revision, array $municipioToRuta, string $tipo): array
    {
        $municipioId = $revision->piv?->municipio !== null ? (int) $revision->piv->municipio : null;
        $rutaInfo = $municipioId !== null ? ($municipioToRuta[$municipioId] ?? null) : null;

        return [
            'tipo' => $tipo,
            'lv_id' => (int) $revision->id,
            'sgip_id' => null,
            'panel_id_sgip' => null,
            'categoria' => null,
            'descripcion' => $revision->decision_notas,
            'piv_id' => $revision->piv_id,
            'parada_cod' => $revision->piv?->parada_cod ? trim((string) $revision->piv->parada_cod) : null,
            'municipio_modulo_id' => $municipioId,
            'km_desde_ciempozuelos' => $rutaInfo['km'] ?? null,
            'ruta_codigo' => $rutaInfo['ruta_codigo'] ?? self::SIN_RUTA_CODIGO,
            'ruta_nombre' => $rutaInfo['ruta_nombre'] ?? self::SIN_RUTA_NOMBRE,
            'fecha_planificada' => $revision->fecha_planificada?->format('Y-m-d'),
            'carry_origen_periodo' => null,
            'carry_origen_status' => null,
        ];
    }

    /**
     * Ordena items por km_desde_ciempozuelos ASC (NULL al final).
     */
    private function sortByKm(Collection $items): Collection
    {
        return $items->sort(function (array $first, array $second): int {
            $firstKm = $first['km_desde_ciempozuelos'];
            $secondKm = $second['km_desde_ciempozuelos'];

            if ($firstKm === null && $secondKm === null) {
                return 0;
            }

            if ($firstKm === null) {
                return 1;
            }

            if ($secondKm === null) {
                return -1;
            }

            return $firstKm <=> $secondKm;
        })->values();
    }
}
