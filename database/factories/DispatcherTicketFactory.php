<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\DispatcherTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DispatcherTicket>
 */
class DispatcherTicketFactory extends Factory
{
    protected $model = DispatcherTicket::class;

    public function definition(): array
    {
        return [
            'delivery_id'      => Delivery::factory()->regional(),
            'dispatcher_id'    => User::factory(),
            'provider_name'    => fake()->company(),
            'provider_type'    => DispatcherTicket::PROVIDER_BUS,
            'ticket_number'    => strtoupper(fake()->bothify('TKT-####')),
            'route_from'       => 'Dar es Salaam',
            'route_to'         => 'Arusha',
            'transport_fare'   => 3500.00,
            'currency'         => 'TZS',
            'issued_at'        => now(),
        ];
    }
}