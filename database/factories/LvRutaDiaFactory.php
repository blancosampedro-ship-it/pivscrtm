<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LvRutaDia;
use App\Models\Tecnico;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LvRutaDia>
 */
class LvRutaDiaFactory extends Factory
{
    protected $model = LvRutaDia::class;

    public function definition(): array
    {
        return [
            'tecnico_id' => Tecnico::factory(),
            'fecha' => now('Europe/Madrid')->toDateString(),
            'status' => LvRutaDia::STATUS_PLANIFICADA,
            'notas_admin' => null,
            'created_by_user_id' => null,
        ];
    }

    public function completada(): self
    {
        return $this->state(fn (): array => [
            'status' => LvRutaDia::STATUS_COMPLETADA,
        ]);
    }
}
