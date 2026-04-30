<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Averia;
use Illuminate\Database\Eloquent\Factories\Factory;

class AveriaFactory extends Factory
{
    protected $model = Averia::class;

    public function definition(): array
    {
        return [
            'averia_id' => $this->faker->unique()->numberBetween(1, 999999),
            'operador_id' => $this->faker->numberBetween(1, 99),
            'piv_id' => $this->faker->numberBetween(1, 999),
            'notas' => $this->faker->sentence(),
            'fecha' => now(),
            'status' => 1,
            'tecnico_id' => $this->faker->numberBetween(1, 99),
        ];
    }
}
