<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\Tecnico;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Persiste un snapshot del PlanificadorDelDiaService como ruta del dia concreta
 * asignada a un tecnico. NO toca tablas legacy ni cron 12b.4.
 */
final class RutaDiaSnapshotService
{
    public function __construct(
        private readonly PlanificadorDelDiaService $planificador,
    ) {}

    public function snapshot(
        int $tecnicoId,
        CarbonInterface $fecha,
        User $admin,
        bool $incluirAmbiguas = true,
    ): LvRutaDia {
        $fechaInmutable = CarbonImmutable::instance($fecha)->setTimezone('Europe/Madrid')->startOfDay();
        $fechaString = $fechaInmutable->format('Y-m-d');

        $tecnico = Tecnico::query()
            ->where('tecnico_id', $tecnicoId)
            ->where('status', 1)
            ->first();

        if ($tecnico === null) {
            throw new DomainException("Técnico {$tecnicoId} no existe o no está activo.");
        }

        $existing = LvRutaDia::query()
            ->where('tecnico_id', $tecnicoId)
            ->whereDate('fecha', $fechaString)
            ->first();

        if ($existing !== null) {
            throw new DomainException("Ya existe ruta para técnico {$tecnicoId} el {$fechaString} (id={$existing->id}).");
        }

        return DB::transaction(function () use ($tecnicoId, $fechaString, $admin, $incluirAmbiguas, $fechaInmutable): LvRutaDia {
            $resultado = $this->planificador->computar($fechaInmutable);

            $ruta = LvRutaDia::create([
                'tecnico_id' => $tecnicoId,
                'fecha' => $fechaString,
                'status' => LvRutaDia::STATUS_PLANIFICADA,
                'created_by_user_id' => $admin->id,
            ]);

            $orden = 1;
            foreach ($resultado['grupos'] as $grupo) {
                foreach ($grupo['items'] as $item) {
                    if (! $incluirAmbiguas && $item['piv_id'] === null) {
                        continue;
                    }

                    $tipo = (string) $item['tipo'];
                    $lvAveriaIccaId = $tipo === LvRutaDiaItem::TIPO_CORRECTIVO ? (int) $item['lv_id'] : null;
                    $lvRevisionPendienteId = in_array($tipo, [
                        LvRutaDiaItem::TIPO_PREVENTIVO,
                        LvRutaDiaItem::TIPO_CARRY_OVER,
                    ], true) ? (int) $item['lv_id'] : null;

                    LvRutaDiaItem::create([
                        'ruta_dia_id' => $ruta->id,
                        'orden' => $orden++,
                        'tipo_item' => $tipo,
                        'lv_averia_icca_id' => $lvAveriaIccaId,
                        'lv_revision_pendiente_id' => $lvRevisionPendienteId,
                        'status' => LvRutaDiaItem::STATUS_PENDIENTE,
                    ]);
                }
            }

            return $ruta->load('items');
        });
    }
}
