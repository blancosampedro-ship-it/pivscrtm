<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InstaladorPiv;
use Illuminate\Database\Eloquent\Factories\Factory;

class InstaladorPivFactory extends Factory
{
    protected $model = InstaladorPiv::class;

    public function definition(): array
    {
        return [
            'instalador_piv_id' => $this->faker->unique()->numberBetween(1, 999999),
            'piv_id' => $this->faker->numberBetween(1, 999),
            'instalador_id' => $this->faker->numberBetween(1, 99),
        ];
    }
}
