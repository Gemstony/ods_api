<?php

namespace Database\Factories;

use App\Models\Dispute;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispute>
 */
class DisputeFactory extends Factory
{
    protected $model = Dispute::class;

    public function definition(): array
    {
        return [
            'opened_by'   => User::factory(),
            'category'    => Dispute::CATEGORY_DELIVERY,
            'subject'     => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'priority'    => Dispute::PRIORITY_NORMAL,
            'status'      => Dispute::STATUS_OPEN,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status'      => Dispute::STATUS_RESOLVED,
            'resolution'  => 'Resolved in favor of the distributor.',
            'resolved_at' => now(),
        ]);
    }
}