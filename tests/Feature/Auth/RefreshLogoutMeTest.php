<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RefreshLogoutMeTest extends TestCase
{
    use RefreshDatabase;

    protected function loginAndGetTokens(User $user): array
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'secret-pass',
        ]);

        $data = $response->json('data');

        return [$data['access_token'], $data['refresh_token']];
    }

    protected function makeActiveUser(string $roleName = Role::OWNER): User
    {
        return User::factory()->role($roleName)->create([
            'password' => Hash::make('secret-pass'),
            'status'   => User::STATUS_ACTIVE,
        ]);
    }

    // ─── Refresh ──────────────────────────────────────────────
    public function test_refresh_with_valid_token_returns_new_tokens(): void
    {
        $user = $this->makeActiveUser();
        [, $refreshToken] = $this->loginAndGetTokens($user);

        $response = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $refreshToken,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Token refreshed.')
            ->assertJsonPath('data.user.role', 'owner')
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'expires_in']]);

        // Two refresh-token rows: one revoked (old), one active (new)
        $this->assertSame(2, RefreshToken::where('user_id', $user->id)->count());
        $this->assertSame(1, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
    }

    public function test_old_refresh_token_is_revoked_after_rotation(): void
    {
        $user = $this->makeActiveUser();
        [, $oldRefresh] = $this->loginAndGetTokens($user);

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $oldRefresh,
        ])->assertStatus(200);

        // Reusing the OLD token must fail
        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $oldRefresh,
        ])
            ->assertStatus(401)
            ->assertJsonFragment(['message' => 'Invalid or expired refresh token.']);
    }

    public function test_expired_refresh_token_is_rejected(): void
    {
        $user = $this->makeActiveUser();
        [, $refreshToken] = $this->loginAndGetTokens($user);

        RefreshToken::query()->update(['expires_at' => now()->subHour()]);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertStatus(401)
            ->assertJsonFragment(['message' => 'Invalid or expired refresh token.']);
    }

    public function test_invalid_refresh_token_returns_401(): void
    {
        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => str_repeat('a', 64),
        ])
            ->assertStatus(401)
            ->assertJsonFragment(['message' => 'Invalid or expired refresh token.']);
    }

    public function test_suspended_user_refresh_returns_403_and_revokes_token(): void
    {
        $user = $this->makeActiveUser();
        [, $refreshToken] = $this->loginAndGetTokens($user);

        $user->update(['status' => User::STATUS_SUSPENDED]);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your account is not active. Please contact the administrator.']);

        $this->assertNotNull(RefreshToken::where('user_id', $user->id)->first()->revoked_at);
    }

    // ─── Logout (this device) ─────────────────────────────────
    public function test_logout_revokes_the_refresh_token(): void
    {
        $user = $this->makeActiveUser();
        [$accessToken, $refreshToken] = $this->loginAndGetTokens($user);

        $this->withHeader('Authorization', "Bearer {$accessToken}")
            ->postJson('/api/v1/auth/logout', ['refresh_token' => $refreshToken])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertNotNull(RefreshToken::first()->revoked_at);
    }

    public function test_logout_without_body_still_succeeds(): void
    {
        $user = $this->makeActiveUser();
        [$accessToken, $refreshToken] = $this->loginAndGetTokens($user);

        $this->withHeader('Authorization', "Bearer {$accessToken}")
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(200);

        // Without a provided refresh token, the stored one is NOT revoked
        $this->assertNull(RefreshToken::first()->revoked_at);
    }

    // ─── Logout all (all devices) ─────────────────────────────
    public function test_logout_all_revokes_every_refresh_token(): void
    {
        $user = $this->makeActiveUser();

        // Three devices → three refresh tokens
        $this->loginAndGetTokens($user);
        $this->loginAndGetTokens($user);
        [$lastAccess] = $this->loginAndGetTokens($user);

        $this->assertSame(3, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());

        $this->withHeader('Authorization', "Bearer {$lastAccess}")
            ->postJson('/api/v1/auth/logout-all')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame(0, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->assertSame('auth.logout_all', AuditLog::where('action', 'auth.logout_all')->first()->action);
    }

    // ─── /me ──────────────────────────────────────────────────
    public function test_me_returns_current_user_with_permissions(): void
    {
        $user = $this->makeActiveUser(Role::MANAGER);
        [$accessToken] = $this->loginAndGetTokens($user);

        $response = $this->withHeader('Authorization', "Bearer {$accessToken}")
            ->getJson('/api/v1/auth/me');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonStructure(['data' => ['permissions']]);
    }

    public function test_me_without_token_returns_401(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_me_with_invalid_token_returns_401(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-jwt')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }
}