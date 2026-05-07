<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LvDerivacion;
use App\Models\LvRutaDiaItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LvDerivacion>
 */
class LvDerivacionFactory extends Factory
{
    protected $model = LvDerivacion::class;

    public function definition(): array
    {
        return [
            'lv_ruta_dia_item_id' => LvRutaDiaItem::factory(),
            'tipo_causa' => LvDerivacion::CAUSA_TERCERO,
            'causa_otros_texto' => null,
            'actor_responsable' => LvDerivacion::ACTOR_INTERNO_WINFIN,
            'actor_notas' => null,
            'notas_derivacion' => null,
            'fecha_derivacion' => now('Europe/Madrid'),
            'derivado_por_user_id' => User::factory()->admin(),
            'status' => LvDerivacion::STATUS_PENDIENTE_TERCERO,
            'fecha_resolucion' => null,
            'resuelto_notas' => null,
            'resuelto_por_user_id' => null,
        ];
    }

    public function enCurso(): self
    {
        return $this->state(fn (): array => ['status' => LvDerivacion::STATUS_EN_CURSO]);
    }

    public function cerrada(string $status = LvDerivacion::STATUS_RESUELTO_EXTERNO): self
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'fecha_resolucion' => now('Europe/Madrid'),
            'resuelto_por_user_id' => User::factory()->admin(),
        ]);
    }
}
