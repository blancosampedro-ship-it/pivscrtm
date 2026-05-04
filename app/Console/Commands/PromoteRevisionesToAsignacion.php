<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RevisionPendientePromotorService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class PromoteRevisionesToAsignacion extends Command
{
    protected $signature = 'lv:promote-revisiones-to-asignacion
                            {--date= : Fecha YYYY-MM-DD, por defecto hoy en Europe/Madrid}';

    protected $description = 'Promueve lv_revision_pendiente requiere_visita+fecha a asignacion legacy. Idempotente.';

    public function handle(RevisionPendientePromotorService $service): int
    {
        $dateOption = $this->option('date');

        try {
            $target = filled($dateOption)
                ? CarbonImmutable::createFromFormat('Y-m-d', (string) $dateOption, 'Europe/Madrid')->startOfDay()
                : CarbonImmutable::now('Europe/Madrid')->startOfDay();
        } catch (Throwable) {
            $this->error("Fecha invalida: {$dateOption}. Formato esperado YYYY-MM-DD.");

            return self::INVALID;
        }

        if ($target === false) {
            $this->error("Fecha invalida: {$dateOption}.");

            return self::INVALID;
        }

        $this->info('Promoviendo lv_revision_pendiente para '.$target->format('Y-m-d').'...');

        $result = $service->promoverDelDia($target);

        $this->table(
            ['Metrica', 'Valor'],
            [
                ['promoted (filas a asignacion)', $result['promoted']],
                ['skipped (ya promocionadas)', $result['skipped']],
            ],
        );

        return self::SUCCESS;
    }
}
