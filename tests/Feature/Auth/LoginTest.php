<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $roleName, array $overrides = []): User
    {
        return User::factory()
            ->role($roleName)
            ->create(array_merge([
                'password' => Hash::make('secret-pass'),
                'status'   => User::STATUS_ACTIVE,
            ], $overrides));
    }

    public function test_login_with_valid_credentials_returns_tokens_and_user(): void
    {
        $user = $this->makeUser(Role::OWNER, ['email' => 'owner@ods.test']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'owner@ods.test',
            'password' => 'secret-pass',
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.role', 'owner')
            ->assertJsonPath('data.user.email', 'owner@ods.test')
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.expires_in', 3600)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'phone', 'role', 'status'],
                    'access_token',
                    'refresh_token',
                    'token_type',
                    'expires_in',
                ],
            ]);

        $this->assertDatabaseCount('refresh_tokens', 1);
        $this->assertSame($user->id, AuditLog::first()->actor_id);
        $this->assertSame('auth.login', AuditLog::first()->action);
    }

    public function test_login_with_wrong_password_returns_generic_401(): void
    {
        $this->makeUser(Role::OWNER, ['email' => 'owner@ods.test']);

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'owner@ods.test',
            'password' => 'wrong',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid email or password.');
    }

    public function test_login_with_unknown_email_returns_same_generic_401(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'nobody@ods.test',
            'password' => 'anything',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid email or password.');
    }

    public function test_login_with_pending_user_returns_403(): void
    {
        $this->makeUser(Role::DISTRIBUTOR, [
            'email'  => 'pending@ods.test',
            'status' => User::STATUS_PENDING,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'pending@ods.test',
            'password' => 'secret-pass',
        ])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'Your account is pending approval. Please wait for the administrator.']);
    }

    public function test_login_with_suspended_user_returns_403(): void
    {
        $this->makeUser(Role::RIDER, [
            'email'  => 'sus@ods.test',
            'status' => User::STATUS_SUSPENDED,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'sus@ods.test',
            'password' => 'secret-pass',
        ])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your account has been suspended. Contact the administrator.']);
    }

    public function test_login_with_deactivated_user_returns_403(): void
    {
        $this->makeUser(Role::MANAGER, [
            'email'  => 'dead@ods.test',
            'status' => User::STATUS_DEACTIVATED,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'dead@ods.test',
            'password' => 'secret-pass',
        ])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your account has been deactivated.']);
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $this->makeUser(Role::OWNER, ['email' => 'owner@ods.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email'    => 'owner@ods.test',
                'password' => 'wrong',
            ])->assertStatus(401);
        }

        // 6th attempt — throttle middleware intercepts
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'owner@ods.test',
            'password' => 'wrong',
        ])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'Too many login attempts. Please wait a minute and try again.']);
    }

    public function test_login_validation_errors_return_422_envelope(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email', 'password']]);
    }
}