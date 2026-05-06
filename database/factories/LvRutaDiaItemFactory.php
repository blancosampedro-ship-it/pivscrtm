<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LvAveriaIcca;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LvRutaDiaItem>
 */
class LvRutaDiaItemFactory extends Factory
{
    protected $model = LvRutaDiaItem::class;

    public function definition(): array
    {
        return [
            'ruta_dia_id' => LvRutaDia::factory(),
            'orden' => $this->faker->numberBetween(1, 99),
            'tipo_item' => LvRutaDiaItem::TIPO_CORRECTIVO,
            'lv_averia_icca_id' => LvAveriaIcca::factory(),
            'lv_revision_pendiente_id' => null,
            'status' => LvRutaDiaItem::STATUS_PENDIENTE,
            'causa_no_resolucion' => null,
            'notas_tecnico' => null,
            'cerrado_at' => null,
        ];
    }
}
