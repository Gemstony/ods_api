<?php

namespace Tests\Feature\Middleware;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class EnsureRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Test routes exercising each middleware configuration.
        Route::middleware(['auth:api', 'role:owner'])
            ->get('/test/owner-only', fn () => response()->json(['ok' => true]));

        Route::middleware(['auth:api', 'role:owner,manager'])
            ->get('/test/owner-or-manager', fn () => response()->json(['ok' => true]));

        Route::middleware(['auth:api', 'role:manager,receiver'])
            ->get('/test/manager-or-receiver', fn () => response()->json(['ok' => true]));
    }

    protected function token(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    // ─── Happy path ────────────────────────────────────────────
    public function test_owner_passes_owner_only_route(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();

        $this->withHeader('Authorization', 'Bearer ' . $this->token($owner))
            ->getJson('/test/owner-only')
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    public function test_manager_passes_owner_or_manager_route(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();

        $this->withHeader('Authorization', 'Bearer ' . $this->token($manager))
            ->getJson('/test/owner-or-manager')
            ->assertStatus(200);
    }

    public function test_receiver_passes_manager_or_receiver_route(): void
    {
        $receiver = User::factory()->role(Role::RECEIVER)->create();

        $this->withHeader('Authorization', 'Bearer ' . $this->token($receiver))
            ->getJson('/test/manager-or-receiver')
            ->assertStatus(200);
    }

    // ─── Wrong role ────────────────────────────────────────────
    public function test_manager_denied_on_owner_only_route(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();

        $this->withHeader('Authorization', 'Bearer ' . $this->token($manager))
            ->getJson('/test/owner-only')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonFragment([
                'message' => 'You do not have access to this resource. Required role: owner. Current role: manager.',
            ]);
    }

    public function test_distributor_denied_on_owner_or_manager_route(): void
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();

        $this->withHeader('Authorization', 'Bearer ' . $this->token($distributor))
            ->getJson('/test/owner-or-manager')
            ->assertStatus(403);
    }

    public function test_rider_denied_on_manager_or_receiver_route(): void
    {
        $rider = User::factory()->role(Role::RIDER)->create();

        $this->withHeader('Authorization', 'Bearer ' . $this->token($rider))
            ->getJson('/test/manager-or-receiver')
            ->assertStatus(403);
    }

    // ─── Status gate ───────────────────────────────────────────
    public function test_suspended_user_denied_even_with_correct_role(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();
        $token = $this->token($owner);

        // Suspended AFTER token was issued
        $owner->update(['status' => User::STATUS_SUSPENDED]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/test/owner-only')
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'Your account has been suspended. Contact the administrator.',
            ]);
    }

    public function test_pending_user_denied(): void
    {
        $user = User::factory()->role(Role::DISTRIBUTOR)->pending()->create();

        $this->withHeader('Authorization', 'Bearer ' . $this->token($user))
            ->getJson('/test/owner-or-manager')
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'Your account is pending approval. Please wait for the administrator.',
            ]);
    }

    public function test_deactivated_user_denied(): void
    {
        $user = User::factory()->role(Role::OWNER)->create([
            'status' => User::STATUS_DEACTIVATED,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token($user))
            ->getJson('/test/owner-only')
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your account has been deactivated.']);
    }

    // ─── Auth gate ─────────────────────────────────────────────
    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/test/owner-only')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }
}