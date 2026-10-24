<?php

namespace Tests\Feature\Middleware;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class EnsureBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;
    protected Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        // Route-param based scoping
        Route::middleware(['auth:api', 'role:manager,receiver', 'branch.scope'])
            ->get('/test/branch/{branch}', fn (Request $r) => response()->json([
                'ok'     => true,
                'branch' => (int) $r->route('branch'),
            ]));

        // Input-based scoping
        Route::middleware(['auth:api', 'role:manager', 'branch.scope'])
            ->post('/test/branch-input', fn () => response()->json(['ok' => true]));

        // Header-based scoping
        Route::middleware(['auth:api', 'role:manager', 'branch.scope'])
            ->get('/test/branch-header', fn () => response()->json(['ok' => true]));

        // No branch hint at all — should pass through for all roles
        Route::middleware(['auth:api', 'role:manager,receiver', 'branch.scope'])
            ->get('/test/branch-none', fn () => response()->json(['ok' => true]));
    }

    protected function token(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    protected function manager(Branch $branch): User
    {
        return User::factory()->role(Role::MANAGER)->atBranch($branch)->create();
    }

    // ─── Owner & Distributor bypass ────────────────────────────
    public function test_owner_bypasses_branch_scope(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();

        $this->withHeader('Authorization', 'Bearer ' . $this->token($owner))
            ->getJson("/test/branch/{$this->branchB->id}")
            ->assertStatus(200)
            ->assertJsonPath('branch', $this->branchB->id);
    }

    public function test_distributor_bypasses_branch_scope(): void
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();

        // Distributor hitting a Manager/Receiver route would still be
        // blocked by EnsureRole, so register a route that only requires auth.
        Route::middleware(['auth:api', 'branch.scope'])
            ->get('/test/branch-auth-only/{branch}', fn () => response()->json(['ok' => true]));

        $this->withHeader('Authorization', 'Bearer ' . $this->token($distributor))
            ->getJson("/test/branch-auth-only/{$this->branchB->id}")
            ->assertStatus(200);
    }

    // ─── Manager — matching branch ─────────────────────────────
    public function test_manager_allowed_on_own_branch_route_param(): void
    {
        $manager = $this->manager($this->branchA);

        $this->withHeader('Authorization', 'Bearer ' . $this->token($manager))
            ->getJson("/test/branch/{$this->branchA->id}")
            ->assertStatus(200)
            ->assertJsonPath('branch', $this->branchA->id);
    }

    public function test_manager_allowed_on_own_branch_input(): void
    {
        $manager = $this->manager($this->branchA);

        $this->withHeader('Authorization', 'Bearer ' . $this->token($manager))
            ->postJson('/test/branch-input', ['branch_id' => $this->branchA->id])
            ->assertStatus(200);
    }

    public function test_manager_allowed_on_own_branch_header(): void
    {
        $manager = $this->manager($this->branchA);

        $this->withHeader('Authorization', 'Bearer ' . $this->token($manager))
            ->withHeader('X-Branch-Id', (string) $this->branchA->id)
            ->getJson('/test/branch-header')
            ->assertStatus(200);
    }

    // ─── Manager — other branch ────────────────────────────────
    public function test_manager_denied_on_other_branch_route_param(): void
    {
        $manager = $this->manager($this->branchA);

        $this->withHeader('Authorization', 'Bearer ' . $this->token($manager))
            ->getJson("/test/branch/{$this->branchB->id}")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'You do not have access to this branch.']);
    }

    public function test_manager_denied_on_other_branch_input(): void
    {
        $manager = $this->manager($this->branchA);

        $this->withHeader('Authorization', 'Bearer ' . $this->token($manager))
            ->postJson('/test/branch-input', ['branch_id' => $this->branchB->id])
            ->assertStatus(403);
    }

    public function test_manager_denied_on_other_branch_header(): void
    {
        $manager = $this->manager($this->branchA);

        $this->withHeader('Authorization', 'Bearer ' . $this->token($manager))
            ->withHeader('X-Branch-Id', (string) $this->branchB->id)
            ->getJson('/test/branch-header')
            ->assertStatus(403);
    }

    // ─── No branch hint ────────────────────────────────────────
    public function test_no_branch_hint_passes_through(): void
    {
        $manager = $this->manager($this->branchA);

        $this->withHeader('Authorization', 'Bearer ' . $this->token($manager))
            ->getJson('/test/branch-none')
            ->assertStatus(200);
    }

    // ─── Manager without branch ────────────────────────────────
    public function test_manager_without_assigned_branch_denied_on_any_branch_param(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create(['branch_id' => null]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token($manager))
            ->getJson("/test/branch/{$this->branchA->id}")
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'Your account is not assigned to a branch. Please contact your administrator.',
            ]);
    }

    public function test_manager_without_assigned_branch_passes_on_no_hint_route(): void
    {
        // No branch in request → controller filtering. Middleware shouldn't
        // block, but for Manager we still enforce the "must have a branch"
        // rule because they can't filter by nothing.
        $manager = User::factory()->role(Role::MANAGER)->create(['branch_id' => null]);

        // This test intentionally documents current behaviour: without a
        // branch binding, Managers are rejected even on pass-through routes.
        $this->withHeader('Authorization', 'Bearer ' . $this->token($manager))
            ->getJson('/test/branch-none')
            ->assertStatus(403);
    }
}