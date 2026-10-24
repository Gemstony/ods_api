<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VerificationCode>
 *
 * Note: this factory stores a code_hash of a known raw value ('123456')
 * so tests can call hashMatches('123456') without needing to retrieve
 * the raw code from the issueFor() helper. Use issueFor() in production
 * code; use this factory only in tests.
 */
class VerificationCodeFactory extends Factory
{
    protected $model = VerificationCode::class;

    public function definition(): array
    {
        return [
            'delivery_id'       => Delivery::factory(),
            'code_type'         => VerificationCode::TYPE_DISTRIBUTOR,
            'code_hash'         => hash('sha256', '123456'),
            'issued_to_user_id' => User::factory(),
            'issued_at'         => now(),
            'expires_at'        => now()->addHours(72),
            'attempt_count'     => 0,
        ];
    }

    public function recipient(): static
    {
        return $this->state(fn () => [
            'code_type' => VerificationCode::TYPE_RECIPIENT,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn () => [
            'verified_at'         => now(),
            'verified_by_user_id' => User::factory(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subHour(),
        ]);
    }
}