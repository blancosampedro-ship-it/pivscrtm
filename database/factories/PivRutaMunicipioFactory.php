<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PivRutaMunicipio>
 */
class PivRutaMunicipioFactory extends Factory
{
    protected $model = PivRutaMunicipio::class;

    public function definition(): array
    {
        return [
            'ruta_id' => PivRuta::factory(),
            'municipio_modulo_id' => $this->faker->unique()->numberBetween(1, 1000000),
            'km_desde_ciempozuelos' => $this->faker->numberBetween(10, 120),
        ];
    }
}
