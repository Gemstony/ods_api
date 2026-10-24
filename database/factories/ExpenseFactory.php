<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'category'    => Expense::CATEGORY_OTHER,
            'amount'      => 10000.00,
            'currency'    => 'TZS',
            'description' => fake()->sentence(),
            'recorded_by' => User::factory(),
            'occurred_at' => now(),
        ];
    }
}