<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tecnico;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tecnico>
 */
class TecnicoFactory extends Factory
{
    protected $model = Tecnico::class;

    public function definition(): array
    {
        return [
            'tecnico_id' => $this->faker->unique()->numberBetween(80000, 99999),
            'usuario' => $this->faker->unique()->userName(),
            'clave' => sha1('factory-default-pass'),
            'email' => $this->faker->unique()->safeEmail(),
            'nombre_completo' => $this->faker->name(),
            'dni' => $this->faker->numerify('########').$this->faker->randomLetter(),
            'carnet_conducir' => 'B'.$this->faker->numerify('########'),
            'direccion' => $this->faker->streetAddress(),
            'ccc' => $this->faker->numerify('############'),
            'n_seguridad_social' => $this->faker->numerify('############'),
            'telefono' => $this->faker->phoneNumber(),
            'status' => 1,
        ];
    }
}
