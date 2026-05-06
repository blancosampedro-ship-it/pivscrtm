<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LvRutaDiaItem;
use App\Models\LvRutaDiaItemImagen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LvRutaDiaItemImagen>
 */
class LvRutaDiaItemImagenFactory extends Factory
{
    protected $model = LvRutaDiaItemImagen::class;

    public function definition(): array
    {
        return [
            'ruta_dia_item_id' => LvRutaDiaItem::factory(),
            'url' => 'piv-images/ruta-dia-item/'.$this->faker->uuid().'.jpg',
            'posicion' => 1,
        ];
    }
}
