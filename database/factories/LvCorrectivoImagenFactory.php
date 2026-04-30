<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LvCorrectivoImagen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LvCorrectivoImagen>
 */
class LvCorrectivoImagenFactory extends Factory
{
    protected $model = LvCorrectivoImagen::class;

    public function definition(): array
    {
        return [
            'correctivo_id' => $this->faker->numberBetween(1, 9999),
            'url' => 'storage/app/public/piv-images/correctivo/'.$this->faker->uuid().'.jpg',
            'posicion' => $this->faker->numberBetween(1, 5),
        ];
    }
}
