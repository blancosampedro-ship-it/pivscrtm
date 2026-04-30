<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReinstaladoPiv;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReinstaladoPivFactory extends Factory
{
    protected $model = ReinstaladoPiv::class;

    public function definition(): array
    {
        return [
            'reinstalado_piv_id' => $this->faker->unique()->numberBetween(1, 999999),
            'piv_id' => $this->faker->numberBetween(1, 999),
            'observaciones' => $this->faker->sentence(),
            'pos' => 1,
        ];
    }
}
