<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PivRuta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PivRuta>
 */
class PivRutaFactory extends Factory
{
    protected $model = PivRuta::class;

    public function definition(): array
    {
        return [
            'codigo' => strtoupper($this->faker->unique()->bothify('R??##')),
            'nombre' => $this->faker->unique()->city(),
            'zona_geografica' => $this->faker->words(3, true),
            'color_hint' => $this->faker->hexColor(),
            'km_medio' => $this->faker->numberBetween(20, 120),
            'sort_order' => $this->faker->numberBetween(1, 99),
        ];
    }
}
