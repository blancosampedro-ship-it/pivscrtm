<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Asignacion;
use Illuminate\Database\Eloquent\Factories\Factory;

class AsignacionFactory extends Factory
{
    protected $model = Asignacion::class;

    public function definition(): array
    {
        return [
            'asignacion_id' => $this->faker->unique()->numberBetween(1, 999999),
            'tecnico_id' => $this->faker->numberBetween(1, 99),
            'fecha' => now()->toDateString(),
            'hora_inicial' => 8,
            'hora_final' => 12,
            'tipo' => Asignacion::TIPO_CORRECTIVO,
            'averia_id' => $this->faker->numberBetween(1, 999999),
            'status' => 1,
        ];
    }

    public function correctivo(): static
    {
        return $this->state(['tipo' => Asignacion::TIPO_CORRECTIVO]);
    }

    public function revision(): static
    {
        return $this->state(['tipo' => Asignacion::TIPO_REVISION]);
    }
}
