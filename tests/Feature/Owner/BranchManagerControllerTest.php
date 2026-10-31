<?php

namespace Tests\Feature\Owner;

use App\Models\Branch;
use App\Models\BranchManager;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchManagerControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
    }

    protected function owner(): User
    {
        return User::factory()->role(Role::OWNER)->create();
    }

    protected function branch(): Branch
    {
        return Branch::factory()->create();
    }

    protected function manager(): User
    {
        return User::factory()->role(Role::MANAGER)->create();
    }

    // ─── Authorization ────────────────────────────────────────
    public function test_all_endpoints_require_authentication(): void
    {
        $branch = $this->branch();

        $this->postJson("/api/v1/owner/branches/{$branch->id}/assign-manager", [])->assertStatus(401);
        $this->deleteJson("/api/v1/owner/branches/{$branch->id}/assign-manager")->assertStatus(401);
        $this->getJson("/api/v1/owner/branches/{$branch->id}/managers")->assertStatus(401);
    }

    public function test_all_endpoints_reject_non_owner_roles(): void
    {
        $manager = $this->manager();
        $branch = $this->branch();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/owner/branches/{$branch->id}/assign-manager", [])
            ->assertStatus(403);
        $this->actingAs($manager, 'api')
            ->deleteJson("/api/v1/owner/branches/{$branch->id}/assign-manager")
            ->assertStatus(403);
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/owner/branches/{$branch->id}/managers")
            ->assertStatus(403);
    }

    // ─── assign ───────────────────────────────────────────────
    public function test_assign_creates_active_assignment(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();
        $manager = $this->manager();

        $response = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/branches/{$branch->id}/assign-manager", [
                'user_id' => $manager->id,
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.current_manager.id', $manager->id);

        $this->assertSame($branch->id, $manager->fresh()->branch_id);
        $this->assertSame(1, BranchManager::where('branch_id', $branch->id)->whereNull('unassigned_at')->count());
    }

    public function test_reassigning_closes_previous_assignment(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();
        $m1 = $this->manager();
        $m2 = $this->manager();

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/branches/{$branch->id}/assign-manager", [
                'user_id' => $m1->id,
            ])
            ->assertStatus(200);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/branches/{$branch->id}/assign-manager", [
                'user_id' => $m2->id,
            ])
            ->assertStatus(200);

        // m1 detached, m2 attached
        $this->assertNull($m1->fresh()->branch_id);
        $this->assertSame($branch->id, $m2->fresh()->branch_id);

        // Only one active assignment
        $this->assertSame(1, BranchManager::where('branch_id', $branch->id)->whereNull('unassigned_at')->count());

        // History preserves both
        $this->assertSame(2, BranchManager::where('branch_id', $branch->id)->count());
    }

    public function test_assign_rejects_non_manager_role(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();
        $rider = User::factory()->role(Role::RIDER)->create();

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/branches/{$branch->id}/assign-manager", [
                'user_id' => $rider->id,
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Only users with the Manager role can be assigned as branch manager.',
            ]);
    }

    public function test_assign_rejects_inactive_manager(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();
        $manager = User::factory()->role(Role::MANAGER)->suspended()->create();

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/branches/{$branch->id}/assign-manager", [
                'user_id' => $manager->id,
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Cannot assign an inactive user as branch manager.',
            ]);
    }

    // ─── unassign ─────────────────────────────────────────────
    public function test_unassign_removes_current_manager(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();
        $manager = $this->manager();
        $branch->assignManager($manager, $owner);

        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/owner/branches/{$branch->id}/assign-manager")
            ->assertStatus(200)
            ->assertJsonPath('data.current_manager', null);

        $this->assertNull($manager->fresh()->branch_id);
        $this->assertSame(0, BranchManager::where('branch_id', $branch->id)->whereNull('unassigned_at')->count());
    }

    public function test_unassign_fails_when_no_manager(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();

        $this->actingAs($owner, 'api')
            ->deleteJson("/api/v1/owner/branches/{$branch->id}/assign-manager")
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This branch has no assigned manager.',
            ]);
    }

    // ─── history ──────────────────────────────────────────────
    public function test_history_returns_assignments_with_metadata(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();
        $m1 = $this->manager();
        $m2 = $this->manager();

        $branch->assignManager($m1, $owner);
        $branch->assignManager($m2, $owner);

        $response = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/owner/branches/{$branch->id}/managers");

        $response
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'user' => ['id', 'name', 'email'],
                        'assigned_at', 'assigned_by',
                        'unassigned_at', 'unassigned_by',
                        'is_active',
                    ],
                ],
            ]);

        // Newest first
        $this->assertSame($m2->id, $response->json('data.0.user.id'));
        $this->assertTrue($response->json('data.0.is_active'));
        $this->assertSame($m1->id, $response->json('data.1.user.id'));
        $this->assertFalse($response->json('data.1.is_active'));
    }
}