<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Revision;
use Illuminate\Database\Eloquent\Factories\Factory;

class RevisionFactory extends Factory
{
    protected $model = Revision::class;

    public function definition(): array
    {
        return [
            'revision_id' => $this->faker->unique()->numberBetween(1, 999999),
            'tecnico_id' => $this->faker->numberBetween(1, 99),
            'asignacion_id' => $this->faker->numberBetween(1, 999999),
            'fecha' => now()->toDateString(),
            'aspecto' => 'OK',
            'funcionamiento' => 'OK',
            'actuacion' => 'OK',
            'audio' => 'OK',
            'fecha_hora' => 'OK',
            'precision_paso' => 'OK',
            'notas' => null,
        ];
    }
}
