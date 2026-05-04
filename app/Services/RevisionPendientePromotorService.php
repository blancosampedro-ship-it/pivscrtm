<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\LvRevisionPendiente;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Promueve revisiones planificadas del dia a averia/asignacion legacy.
 */
final class RevisionPendientePromotorService
{
    public const NOTAS_AVERIA_STUB = 'Revisión preventiva mensual';

    /**
     * @return array{promoted: int, skipped: int}
     */
    public function promoverDelDia(?DateTimeInterface $date = null): array
    {
        $target = CarbonImmutable::instance($date ?? now('Europe/Madrid'))
            ->setTimezone('Europe/Madrid')
            ->startOfDay();

        return DB::transaction(function () use ($target): array {
            $promoted = 0;

            LvRevisionPendiente::query()
                ->requiereVisitaParaFecha($target)
                ->noPromocionadas()
                ->cursor()
                ->each(function (LvRevisionPendiente $revisionPendiente) use ($target, &$promoted): void {
                    $averia = Averia::create([
                        'piv_id' => $revisionPendiente->piv_id,
                        'notas' => self::NOTAS_AVERIA_STUB,
                        'status' => 1,
                    ]);

                    $asignacion = Asignacion::create([
                        'averia_id' => $averia->averia_id,
                        'tipo' => Asignacion::TIPO_REVISION,
                        'fecha' => $target->format('Y-m-d'),
                        'status' => 1,
                    ]);

                    $revisionPendiente->update(['asignacion_id' => $asignacion->asignacion_id]);
                    $promoted++;
                });

            return [
                'promoted' => $promoted,
                'skipped' => 0,
            ];
        });
    }
}
