<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'user_id'  => User::factory(),
            'type'     => 'delivery.assigned',
            'title'    => fake()->sentence(4),
            'body'     => fake()->sentence(),
            'data'     => ['delivery_id' => 1],
            'channels' => ['in_app'],
            'status'   => Notification::STATUS_PENDING,
            'priority' => Notification::PRIORITY_NORMAL,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status'  => Notification::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function read(): static
    {
        return $this->state(fn () => [
            'status'  => Notification::STATUS_SENT,
            'sent_at' => now(),
            'read_at' => now(),
        ]);
    }
}