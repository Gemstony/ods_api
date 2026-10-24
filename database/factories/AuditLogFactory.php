<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        $actor = User::factory();

        return [
            'actor_id'     => $actor,
            'actor_role'   => 'owner',
            'actor_label'  => fake()->name(),
            'action'       => 'user.created',
            'description'  => fake()->sentence(),
            'subject_type' => null,
            'subject_id'   => null,
        ];
    }
}