<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\U1;
use Illuminate\Database\Eloquent\Factories\Factory;

class U1Factory extends Factory
{
    protected $model = U1::class;

    public function definition(): array
    {
        return [
            'user_id' => $this->faker->unique()->numberBetween(1, 999999),
            'username' => $this->faker->userName(),
            'email' => $this->faker->safeEmail(),
            'password' => sha1('password'),
        ];
    }
}
