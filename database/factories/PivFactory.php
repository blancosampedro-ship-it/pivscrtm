<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Piv;
use Illuminate\Database\Eloquent\Factories\Factory;

class PivFactory extends Factory
{
    protected $model = Piv::class;

    public function definition(): array
    {
        return [
            'piv_id' => $this->faker->unique()->numberBetween(1, 999999),
            'parada_cod' => $this->faker->bothify('PARADA-####'),
            'direccion' => $this->faker->streetAddress(),
            'municipio' => '0',
            'status' => 1,
        ];
    }
}
