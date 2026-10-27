<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterDistributorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Only the RoleSeeder is needed — registration is the whole point,
        // so we don't want a seeded Owner influencing anything.
        (new RoleSeeder())->run();
    }

    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'John Distributor',
            'distributor_id' => 'NEO12345',
            'email' => 'john@example.com',
            'phone' => '0712345678',
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ], $overrides);
    }

    // ─── Success ───────────────────────────────────────────────
    public function test_successful_registration_creates_pending_distributor(): void
    {
        $response = $this->postJson('/api/v1/auth/register/distributor', $this->validPayload());

        $response
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', User::STATUS_PENDING)
            ->assertJsonPath('data.role', Role::DISTRIBUTOR)
            ->assertJsonPath('data.email', 'john@example.com')
            ->assertJsonPath('data.distributor_id', 'NEO12345')
            ->assertJsonPath('data.phone', '0712345678')
            ->assertJsonMissingPath('data.password');

        $user = User::where('email', 'john@example.com')->firstOrFail();
        $this->assertSame(Role::DISTRIBUTOR, $user->role->name);
        $this->assertSame(User::STATUS_PENDING, $user->status);
        $this->assertSame('NEO12345', $user->distributor_id);
        // Password was hashed
        $this->assertNotSame('SecretPass123!', $user->password);
        // Audit log entry was created
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.self_registered',
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'actor_id' => null,
        ]);
    }

    // ─── Pending users cannot log in until approved ────────────
    public function test_newly_registered_distributor_cannot_log_in_until_approved(): void
    {
        $this->postJson('/api/v1/auth/register/distributor', $this->validPayload())
            ->assertStatus(201);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'john@example.com',
            'password' => 'SecretPass123!',
        ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'Your account is pending approval. Please wait for the administrator.',
            ]);
    }

    // ─── Duplicate fields ──────────────────────────────────────
    public function test_duplicate_email_returns_422(): void
    {
        $role = Role::where('name', Role::DISTRIBUTOR)->firstOrFail();
        User::factory()->create([
            'role_id' => $role->id,
            'email' => 'john@example.com',
        ]);

        $this->postJson('/api/v1/auth/register/distributor', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email']])
            ->assertJsonFragment(['email' => ['This email is already registered.']]);
    }

    public function test_duplicate_phone_returns_422(): void
    {
        $role = Role::where('name', Role::DISTRIBUTOR)->firstOrFail();
        User::factory()->create([
            'role_id' => $role->id,
            'phone' => '0712345678',
        ]);

        $this->postJson('/api/v1/auth/register/distributor', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['phone']]);
    }

    public function test_duplicate_distributor_id_returns_422(): void
    {
        $role = Role::where('name', Role::DISTRIBUTOR)->firstOrFail();
        User::factory()->create([
            'role_id' => $role->id,
            'distributor_id' => 'NEO12345',
        ]);

        $this->postJson('/api/v1/auth/register/distributor', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['distributor_id']]);
    }

    // ─── Validation ────────────────────────────────────────────
    public function test_missing_required_fields_returns_422(): void
    {
        $this->postJson('/api/v1/auth/register/distributor', [])
            ->assertStatus(422)
            ->assertJsonStructure([
                'errors' => ['name', 'distributor_id', 'email', 'phone', 'password'],
            ]);
    }

    public function test_weak_password_returns_422(): void
    {
        $this->postJson('/api/v1/auth/register/distributor', $this->validPayload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    public function test_mismatched_password_confirmation_returns_422(): void
    {
        $this->postJson('/api/v1/auth/register/distributor', $this->validPayload([
            'password_confirmation' => 'DifferentPass123!',
        ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    public function test_invalid_phone_format_returns_422(): void
    {
        foreach (['12345', '+1 555 0100', '0712', 'not-a-phone'] as $i => $bad) {
            // Give each iteration a distinct REMOTE_ADDR so the "register"
            // rate limiter (3/min per IP) doesn't 429 iteration 4. This
            // also implicitly verifies the limiter is keyed by client IP.
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/v1/auth/register/distributor', $this->validPayload([
                    'phone' => $bad,
                ]))
                ->assertStatus(422)
                ->assertJsonStructure(['errors' => ['phone']]);
        }
    }
    // ─── Rate limiting ─────────────────────────────────────────
    public function test_register_is_rate_limited_per_ip(): void
    {
        // 3 allowed, 4th blocked. Use different emails so email-uniqueness
        // isn't the reason any of them fails.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/register/distributor', $this->validPayload([
                'email' => "attempt{$i}@example.com",
            ]));
        }

        $this->postJson('/api/v1/auth/register/distributor', $this->validPayload([
            'email' => 'attempt3@example.com',
        ]))
            ->assertStatus(429)
            ->assertJsonFragment([
                'message' => 'Too many registration attempts. Please wait a minute and try again.',
            ]);
    }
}