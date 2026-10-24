<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\Earning;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Earning>
 */
class EarningFactory extends Factory
{
    protected $model = Earning::class;

    public function definition(): array
    {
        return [
            'user_id'     => User::factory(),
            'delivery_id' => Delivery::factory(),
            'type'        => Earning::TYPE_RIDER_BASELINE,
            'amount'      => 1200.00,
            'currency'    => 'TZS',
            'status'      => Earning::STATUS_PENDING,
            'earned_at'   => now(),
        ];
    }

    public function riderBonus(): static
    {
        return $this->state(fn () => [
            'type'   => Earning::TYPE_RIDER_BONUS,
            'amount' => 150.00,
        ]);
    }

    public function dispatcherFlat(): static
    {
        return $this->state(fn () => [
            'type'   => Earning::TYPE_DISPATCHER_FLAT,
            'amount' => 500.00,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status'      => Earning::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => User::factory(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status'  => Earning::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }
}