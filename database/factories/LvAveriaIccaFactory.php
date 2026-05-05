<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LvAveriaIcca;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LvAveriaIcca>
 */
class LvAveriaIccaFactory extends Factory
{
    protected $model = LvAveriaIcca::class;

    public function definition(): array
    {
        return [
            'sgip_id' => str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 7, '0', STR_PAD_LEFT),
            'panel_id_sgip' => 'PANEL '.$this->faker->unique()->numberBetween(10000, 99999),
            'piv_id' => null,
            'categoria' => $this->faker->randomElement(LvAveriaIcca::CATEGORIAS_CONOCIDAS),
            'descripcion' => $this->faker->sentence(),
            'notas' => null,
            'estado_externo' => 'asignada',
            'asignada_a' => 'SGIP_winfin',
            'activa' => true,
            'fecha_import' => now(),
            'archivo_origen' => 'SGIP_winfin_test.csv',
            'imported_by_user_id' => null,
            'marked_inactive_at' => null,
        ];
    }

    public function activa(): self
    {
        return $this->state(fn (): array => [
            'activa' => true,
            'marked_inactive_at' => null,
        ]);
    }

    public function inactiva(): self
    {
        return $this->state(fn (): array => [
            'activa' => false,
            'marked_inactive_at' => now(),
        ]);
    }
}
