<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Modulo;
use Illuminate\Database\Eloquent\Factories\Factory;

class ModuloFactory extends Factory
{
    protected $model = Modulo::class;

    public function definition(): array
    {
        return [
            'modulo_id' => $this->faker->unique()->numberBetween(1, 999999),
            'nombre' => $this->faker->word(),
            'tipo' => Modulo::TIPO_MUNICIPIO,
        ];
    }

    public function municipio(?string $nombre = null): static
    {
        return $this->state(fn () => [
            'tipo' => Modulo::TIPO_MUNICIPIO,
            'nombre' => $nombre ?? $this->faker->city(),
        ]);
    }

    public function industria(): static
    {
        return $this->state(['tipo' => Modulo::TIPO_INDUSTRIA]);
    }
}
