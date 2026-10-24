<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name'            => 'Branch ' . fake()->unique()->city(),
            'code'            => strtoupper(fake()->unique()->bothify('???-##')),
            'address'         => fake()->address(),
            'latitude'        => fake()->latitude(-11.0, -1.0),   // Tanzania bounds
            'longitude'       => fake()->longitude(29.0, 41.0),
            'operating_hours' => [
                'monday'    => ['open' => '08:00', 'close' => '18:00'],
                'tuesday'   => ['open' => '08:00', 'close' => '18:00'],
                'wednesday' => ['open' => '08:00', 'close' => '18:00'],
                'thursday'  => ['open' => '08:00', 'close' => '18:00'],
                'friday'    => ['open' => '08:00', 'close' => '18:00'],
                'saturday'  => ['open' => '09:00', 'close' => '14:00'],
                'sunday'    => null,
            ],
            'status' => Branch::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Branch::STATUS_INACTIVE]);
    }
}