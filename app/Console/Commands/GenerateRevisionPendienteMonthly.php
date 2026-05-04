<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RevisionPendienteSeederService;
use Illuminate\Console\Command;

final class GenerateRevisionPendienteMonthly extends Command
{
    protected $signature = 'lv:generate-revision-pendiente-monthly
                            {--year= : Anio, por defecto el actual en Europe/Madrid}
                            {--month= : Mes 1-12, por defecto el actual en Europe/Madrid}';

    protected $description = 'Genera filas lv_revision_pendiente para los paneles activos del mes indicado. Idempotente.';

    public function handle(RevisionPendienteSeederService $service): int
    {
        $now = now('Europe/Madrid');
        $year = (int) ($this->option('year') ?? $now->year);
        $month = (int) ($this->option('month') ?? $now->month);

        if ($month < 1 || $month > 12) {
            $this->error("Mes invalido: {$month}. Debe estar entre 1 y 12.");

            return self::INVALID;
        }

        $this->info("Generando lv_revision_pendiente para {$year}-{$month}...");

        $result = $service->generarMes($year, $month);

        $this->table(
            ['Metrica', 'Valor'],
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
