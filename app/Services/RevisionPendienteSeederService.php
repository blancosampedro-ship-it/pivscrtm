<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use Illuminate\Support\Facades\DB;

/**
 * Genera filas mensuales de revision pendiente para paneles no archivados.
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
            $totalPanels = 0;

            [$previousYear, $previousMonth] = $this->previousPeriod($year, $month);

            $previousIncompletas = LvRevisionPendiente::query()
                ->incompletas()
                ->delMes($previousYear, $previousMonth)
                ->get(['id', 'piv_id'])
                ->keyBy('piv_id');

            Piv::query()->notArchived()->cursor()->each(function (Piv $piv) use (
                $year,
                $month,
                $previousIncompletas,
                &$created,
                &$carryUpdated,
                &$alreadyExisted,
                &$totalPanels,
            ): void {
                $totalPanels++;

                $row = LvRevisionPendiente::query()->firstOrCreate(
                    [
                        'piv_id' => $piv->piv_id,
                        'periodo_year' => $year,
                        'periodo_month' => $month,
                    ],
                    ['status' => LvRevisionPendiente::STATUS_PENDIENTE],
                );

                if (! $row->wasRecentlyCreated) {
                    $alreadyExisted++;

                    return;
                }

                $created++;

                $previous = $previousIncompletas->get($piv->piv_id);

                if ($previous === null) {
                    return;
                }

                $row->carry_over_origen_id = $previous->id;
                $row->save();
                $carryUpdated++;
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
     * @return array{0: int, 1: int}
     */
    private function previousPeriod(int $year, int $month): array
    {
        if ($month === 1) {
            return [$year - 1, 12];
        }

        return [$year, $month - 1];
    }
}
