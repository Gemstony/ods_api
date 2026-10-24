<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
{
    protected $model = PaymentTransaction::class;

    public function definition(): array
    {
        return [
            'payment_id'    => Payment::factory(),
            'type'          => PaymentTransaction::TYPE_INITIATE,
            'provider'      => 'clickpesa',
            'status'        => PaymentTransaction::STATUS_PENDING,
            'attempted_at'  => now(),
        ];
    }
}