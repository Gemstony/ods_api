<?php

namespace Database\Factories;

use App\Models\Payout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    public function definition(): array
    {
        return [
            'user_id'      => User::factory(),
            'amount'       => 50000.00,
            'currency'     => 'TZS',
            'method'       => Payout::METHOD_MOBILE,
            'status'       => Payout::STATUS_PENDING,
            'requested_at' => now(),
        ];
    }
}