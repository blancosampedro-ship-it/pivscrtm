<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Modulo;
use App\Models\PivZona;
use App\Models\PivZonaMunicipio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PivZonaMunicipio>
 */
class PivZonaMunicipioFactory extends Factory
{
    protected $model = PivZonaMunicipio::class;

    public function definition(): array
    {
        return [
            'zona_id' => PivZona::factory(),
            'municipio_modulo_id' => Modulo::factory()->municipio(),
        ];
    }
}
