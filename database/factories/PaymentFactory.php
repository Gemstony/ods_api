<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'delivery_id'  => Delivery::factory(),
            'stage'        => Payment::STAGE_FULL,
            'amount'       => 1500.00,
            'currency'     => 'TZS',
            'method'       => Payment::METHOD_CLICKPESA,
            'status'       => Payment::STATUS_PENDING,
            'initiated_by' => User::factory(),
            'initiated_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status'  => Payment::STATUS_COMPLETED,
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status'         => Payment::STATUS_FAILED,
            'failed_at'      => now(),
            'failure_reason' => 'Insufficient funds',
        ]);
    }
}