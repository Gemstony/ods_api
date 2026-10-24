<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryItem>
 */
class DeliveryItemFactory extends Factory
{
    protected $model = DeliveryItem::class;

    public function definition(): array
    {
        return [
            'delivery_id'   => Delivery::factory(),
            'product_name'  => fake()->words(2, true),
            'product_code'  => strtoupper(fake()->bothify('???-###')),
            'quantity'      => fake()->numberBetween(1, 5),
            'sort_order'    => 0,
        ];
    }

    public function exgoOrder(string $orderId = 'EXGO-12345'): static
    {
        return $this->state(fn () => [
            'exgo_order_id' => $orderId,
            'product_name'  => null,
            'product_code'  => null,
            'quantity'      => null,
        ]);
    }
}