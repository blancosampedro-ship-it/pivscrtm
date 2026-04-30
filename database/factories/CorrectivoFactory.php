<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Correctivo;
use Illuminate\Database\Eloquent\Factories\Factory;

class CorrectivoFactory extends Factory
{
    protected $model = Correctivo::class;

    public function definition(): array
    {
        return [
            'correctivo_id' => $this->faker->unique()->numberBetween(1, 999999),
            'tecnico_id' => $this->faker->numberBetween(1, 99),
            'asignacion_id' => $this->faker->numberBetween(1, 999999),
            'tiempo' => '1.0',
            'contrato' => 1,
            'facturar_horas' => 0,
            'facturar_desplazamiento' => 0,
            'facturar_recambios' => 0,
            'recambios' => 'NO',
            'diagnostico' => $this->faker->sentence(),
            'estado_final' => 'OK',
        ];
    }
}
