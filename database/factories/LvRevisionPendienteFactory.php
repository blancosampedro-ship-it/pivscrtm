<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LvRevisionPendiente>
 */
class LvRevisionPendienteFactory extends Factory
{
    protected $model = LvRevisionPendiente::class;

    public function definition(): array
    {
        return [
            'piv_id' => Piv::factory(),
            'periodo_year' => 2026,
            'periodo_month' => 5,
            'status' => LvRevisionPendiente::STATUS_PENDIENTE,
            'fecha_planificada' => null,
            'decision_user_id' => null,
            'decision_at' => null,
            'decision_notas' => null,
            'carry_over_origen_id' => null,
            'asignacion_id' => null,
        ];
    }

    public function pendiente(): self
    {
        return $this->state(fn (): array => ['status' => LvRevisionPendiente::STATUS_PENDIENTE]);
    }

    public function verificadaRemoto(): self
    {
        return $this->state(fn (): array => [
            'status' => LvRevisionPendiente::STATUS_VERIFICADA_REMOTO,
            'decision_at' => now(),
        ]);
    }

    public function requiereVisita(): self
    {
        return $this->state(fn (): array => [
            'status' => LvRevisionPendiente::STATUS_REQUIERE_VISITA,
            'fecha_planificada' => now()->toDateString(),
            'decision_at' => now(),
        ]);
    }

    public function excepcion(): self
    {
        return $this->state(fn (): array => [
            'status' => LvRevisionPendiente::STATUS_EXCEPCION,
            'decision_at' => now(),
        ]);
    }

    public function completada(): self
    {
        return $this->state(fn (): array => [
            'status' => LvRevisionPendiente::STATUS_COMPLETADA,
        ]);
    }
}
