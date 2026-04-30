<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tecnico;
use Illuminate\Database\Eloquent\Factories\Factory;

class TecnicoFactory extends Factory
{
    protected $model = Tecnico::class;

    public function definition(): array
    {
        return [
            'tecnico_id' => $this->faker->unique()->numberBetween(1, 999999),
            'usuario' => $this->faker->userName(),
            'clave' => sha1('password'),
            'email' => $this->faker->safeEmail(),
            'nombre_completo' => $this->faker->name(),
            'status' => 1,
        ];
    }
}
