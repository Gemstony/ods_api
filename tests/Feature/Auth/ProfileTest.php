<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function makeActiveUser(string $roleName = Role::DISTRIBUTOR): User
    {
        return User::factory()->role($roleName)->create([
            'password' => Hash::make('secret-pass'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    protected function accessTokenFor(User $user): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ]);

        return $response->json('data.access_token');
    }

    // ─── Update profile ───────────────────────────────────────

    public function test_update_profile_changes_name_and_phone(): void
    {
        $user = $this->makeActiveUser(Role::RIDER);
        $token = $this->accessTokenFor($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/profile', [
                'name' => 'New Rider Name',
                'phone' => '0712345678',
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'New Rider Name')
            ->assertJsonPath('data.phone', '0712345678')
            ->assertJsonPath('data.role', 'rider');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Rider Name',
            'phone' => '0712345678',
        ]);
        $this->assertSame(
            'profile.updated',
            AuditLog::where('action', 'profile.updated')->first()->action,
        );
    }

    public function test_update_profile_ignores_email_even_if_sent(): void
    {
        $user = $this->makeActiveUser();
        $token = $this->accessTokenFor($user);
        $originalEmail = $user->email;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/profile', [
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => 'attacker@example.com',
            ])
            ->assertStatus(200);

        $this->assertSame($originalEmail, $user->fresh()->email);
    }

    public function test_update_profile_rejects_duplicate_phone(): void
    {
        $user = $this->makeActiveUser();
        $other = $this->makeActiveUser(Role::RECEIVER);
        $token = $this->accessTokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/profile', [
                'name' => $user->name,
                'phone' => $other->phone,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_update_profile_requires_auth(): void
    {
        $this->putJson('/api/v1/auth/profile', [
            'name' => 'Nobody',
            'phone' => '0712345678',
        ])->assertStatus(401);
    }

    // ─── Change password ──────────────────────────────────────

    public function test_change_password_with_correct_current_password(): void
    {
        $user = $this->makeActiveUser(Role::MANAGER);
        $token = $this->accessTokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'secret-pass',
                'password' => 'brand-new-pass',
                'password_confirmation' => 'brand-new-pass',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertSame(
            'auth.password_changed',
            AuditLog::where('action', 'auth.password_changed')->first()->action,
        );
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = $this->makeActiveUser();
        $token = $this->accessTokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'wrong-pass',
                'password' => 'brand-new-pass',
                'password_confirmation' => 'brand-new-pass',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('secret-pass', $user->fresh()->password));
    }

    public function test_change_password_requires_confirmation_match(): void
    {
        $user = $this->makeActiveUser();
        $token = $this->accessTokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'secret-pass',
                'password' => 'brand-new-pass',
                'password_confirmation' => 'different-pass',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_change_password_requires_auth(): void
    {
        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'x',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertStatus(401);
    }
}
