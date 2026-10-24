<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    protected $model = Delivery::class;

    public function definition(): array
    {
        $branch = Branch::factory();
        $distributor = User::factory()->role(Role::DISTRIBUTOR);

        return [
            'distributor_id'       => $distributor,
            'pickup_branch_id'     => $branch,
            'service_type'         => Delivery::SERVICE_RECEIVE,
            'status'               => Delivery::STATUS_PENDING_PAYMENT,
            'pickup_address'       => fake()->address(),
            'pickup_latitude'      => fake()->latitude(-7.0, -6.5),
            'pickup_longitude'     => fake()->longitude(39.0, 39.5),
            'destination_address'  => fake()->address(),
            'destination_latitude' => fake()->latitude(-7.0, -6.5),
            'destination_longitude'=> fake()->longitude(39.0, 39.5),
            'distance_km'          => fake()->randomFloat(2, 1, 30),
            'rate_per_km'          => 500.00,
            'minimum_fee'          => 1000.00,
            'rounding_increment'   => 500,
            'calculated_fee'       => 1500.00,
            'ods_delivery_fee'     => 1500.00,
            'total_amount'         => 1500.00,
            'currency'             => 'TZS',
        ];
    }

    public function receive(): static
    {
        return $this->state(fn () => [
            'service_type'       => Delivery::SERVICE_RECEIVE,
            'recipient_name'     => null,
            'recipient_phone'    => null,
        ]);
    }

    public function send(): static
    {
        return $this->state(fn () => [
            'service_type'    => Delivery::SERVICE_SEND,
            'pickup_branch_id'=> null,
            'recipient_name'  => fake()->name(),
            'recipient_phone' => '07' . fake()->numerify('########'),
        ]);
    }

    public function regional(): static
    {
        return $this->state(fn () => [
            'service_type'         => Delivery::SERVICE_REGIONAL,
            'recipient_name'       => fake()->name(),
            'recipient_phone'      => '07' . fake()->numerify('########'),
            'destination_region'   => 'Arusha',
            'destination_district' => 'Arusha City',
            'destination_bus_stop' => 'Nairobi Road Station',
            'bus_cargo_fare'       => 3500.00,
            'total_amount'         => 1500.00 + 3500.00,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => Delivery::STATUS_PAID]);
    }

    public function delivered(): static
    {
        return $this->state(fn () => [
            'status'       => Delivery::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }
}