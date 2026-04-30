<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Operador;
use Illuminate\Database\Eloquent\Factories\Factory;

class OperadorFactory extends Factory
{
    protected $model = Operador::class;

    public function definition(): array
    {
        return [
            'operador_id' => $this->faker->unique()->numberBetween(1, 999999),
            'usuario' => $this->faker->userName(),
            'clave' => sha1('password'),
            'email' => $this->faker->safeEmail(),
            'razon_social' => $this->faker->company(),
            'status' => 1,
        ];
    }
}
