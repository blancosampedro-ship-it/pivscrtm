<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PivImagen;
use Illuminate\Database\Eloquent\Factories\Factory;

class PivImagenFactory extends Factory
{
    protected $model = PivImagen::class;

    public function definition(): array
    {
        return [
            'piv_imagen_id' => $this->faker->unique()->numberBetween(1, 999999),
            'piv_id' => $this->faker->numberBetween(1, 999),
            'url' => $this->faker->imageUrl(),
            'posicion' => 1,
        ];
    }
}
