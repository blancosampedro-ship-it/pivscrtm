<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LvReportePeriodico;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LvReportePeriodico>
 */
class LvReportePeriodicoFactory extends Factory
{
    protected $model = LvReportePeriodico::class;

    public function definition(): array
    {
        return [
            'tipo' => LvReportePeriodico::TIPO_MENSUAL,
            'anyo' => 2026,
            'mes' => 5,
            'generated_at' => now('Europe/Madrid'),
            'generated_by_user_id' => User::factory()->admin(),
            'pdf_path' => 'reportes-periodicos/2026/mensual/reporte-mensual-2026-05.pdf',
            'xlsx_path' => 'reportes-periodicos/2026/mensual/reporte-mensual-2026-05.xlsx',
            'metadata_json' => ['periodo' => ['label' => 'Mayo 2026']],
        ];
    }

    public function anual(): self
    {
        return $this->state(fn (): array => [
            'tipo' => LvReportePeriodico::TIPO_ANUAL,
            'mes' => null,
            'pdf_path' => 'reportes-periodicos/2026/anual/reporte-anual-2026.pdf',
            'xlsx_path' => 'reportes-periodicos/2026/anual/reporte-anual-2026.xlsx',
            'metadata_json' => ['periodo' => ['label' => 'Año 2026']],
        ]);
    }
}
