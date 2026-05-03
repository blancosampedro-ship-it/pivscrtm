<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PivZona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PivZona>
 */
class PivZonaFactory extends Factory
{
    protected $model = PivZona::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->unique()->city(),
            'color_hint' => $this->faker->hexColor(),
            'sort_order' => $this->faker->numberBetween(1, 99),
        ];
    }
}
