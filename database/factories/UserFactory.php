<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            'legacy_kind' => 'tecnico',
            'legacy_id' => $sequence,
            'email' => $this->faker->unique()->safeEmail(),
            'name' => $this->faker->name(),
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(['legacy_kind' => 'admin']);
    }

    public function tecnico(): static
    {
        return $this->state(['legacy_kind' => 'tecnico']);
    }

    public function operador(): static
    {
        return $this->state(['legacy_kind' => 'operador']);
    }

    public function legacyOnlyNoBcrypt(): static
    {
        return $this->state([
            'password' => null,
            'legacy_password_sha1' => sha1('legacypwd'),
            'lv_password_migrated_at' => null,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}
