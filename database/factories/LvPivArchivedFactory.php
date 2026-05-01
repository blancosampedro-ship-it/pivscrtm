<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LvPivArchived;
use Illuminate\Database\Eloquent\Factories\Factory;

class LvPivArchivedFactory extends Factory
{
    protected $model = LvPivArchived::class;

    public function definition(): array
    {
        return [
            'piv_id' => $this->faker->unique()->numberBetween(1, 999999),
            'archived_at' => now(),
            'archived_by_user_id' => null,
            'reason' => 'Auto-generated factory archive',
        ];
    }
}
