<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        $role = Role::firstOrCreate(
            ['name' => Role::DISTRIBUTOR],
            ['display_name' => 'Distributor'],
        );

        return [
            'role_id'           => $role->id,
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'phone'             => '07' . fake()->numerify('########'),
            'email_verified_at' => now(),
            'password'          => static::$password ??= Hash::make('password'),
            'status'            => User::STATUS_ACTIVE,
            'remember_token'    => Str::random(10),
        ];
    }

    public function role(string $roleName): static
    {
        return $this->state(function () use ($roleName) {
            $role = Role::firstOrCreate(
                ['name' => $roleName],
                ['display_name' => ucfirst($roleName)],
            );

            return ['role_id' => $role->id];
        });
    }

    public function atBranch(Branch $branch): static
    {
        return $this->state(fn () => ['branch_id' => $branch->id]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_PENDING]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_SUSPENDED]);
    }
}