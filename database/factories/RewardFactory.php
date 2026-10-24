<?php

namespace Database\Factories;

use App\Models\Reward;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reward>
 */
class RewardFactory extends Factory
{
    protected $model = Reward::class;

    public function definition(): array
    {
        return [
            'distributor_id'       => User::factory()->role(Role::DISTRIBUTOR),
            'current_month'        => now()->format('Y-m'),
            'monthly_count'        => 0,
            'rolling_count'        => 0,
            'rolling_window_start' => now(),
            'tier1_available'      => false,
            'tier2_available'      => false,
        ];
    }

    public function tier1Available(): static
    {
        return $this->state(fn () => [
            'monthly_count'    => 20,
            'tier1_available'  => true,
            'tier1_reached_at' => now(),
            'tier1_expires_at' => now()->addDays(30),
        ]);
    }

    public function tier2Available(): static
    {
        return $this->state(fn () => [
            'rolling_count'    => 50,
            'tier2_available'  => true,
            'tier2_reached_at' => now(),
            'tier2_expires_at' => now()->addDays(30),
        ]);
    }
}