<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchManager;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema + behaviour tests for Sub-phase 2.2 — branches, the users FK,
 * and the manager assignment history.
 */
class BranchSchemaTest extends TestCase
{
    use RefreshDatabase;

    // ─── Tables and columns ─────────────────────────────────────
    public function test_branches_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('branches'));
        $this->assertTrue(Schema::hasTable('branch_managers'));

        $cols = Schema::getColumnListing('branches');
        foreach ([
            'id', 'name', 'code', 'address', 'latitude', 'longitude',
            'operating_hours', 'status', 'created_by',
            'created_at', 'updated_at', 'deleted_at',
        ] as $col) {
            $this->assertContains($col, $cols, "Missing branches column: $col");
        }
    }

    // ─── FK constraint on users.branch_id ──────────────────────
    public function test_force_deleting_a_branch_nulls_users_branch_id(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->atBranch($branch)->create();

        $this->assertSame($branch->id, $user->fresh()->branch_id);

        $branch->forceDelete();

        $this->assertNull($user->fresh()->branch_id);
    }

    public function test_soft_deleting_a_branch_keeps_user_branch_id(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->atBranch($branch)->create();

        $branch->delete(); // soft delete

        $this->assertSame($branch->id, $user->fresh()->branch_id);
    }

    // ─── Relations ─────────────────────────────────────────────
    public function test_user_branch_relation_and_branch_users_relation(): void
    {
        $branch = Branch::factory()->create();
        $rider1 = User::factory()->role(Role::RIDER)->atBranch($branch)->create();
        $rider2 = User::factory()->role(Role::RIDER)->atBranch($branch)->create();

        $this->assertSame($branch->id, $rider1->branch->id);
        $this->assertCount(2, $branch->users);
        $this->assertTrue($branch->users->contains($rider1));
        $this->assertTrue($branch->users->contains($rider2));
    }

    // ─── assignManager ─────────────────────────────────────────
    public function test_assign_manager_creates_active_assignment(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();
        $branch = Branch::factory()->create(['created_by' => $owner->id]);
        $manager = User::factory()->role(Role::MANAGER)->create();

        $assignment = $branch->assignManager($manager, $owner);

        $this->assertTrue($assignment->isActive());
        $this->assertSame($manager->id, $assignment->user_id);
        $this->assertSame($owner->id, $assignment->assigned_by);
        $this->assertSame($manager->id, $branch->currentManager()?->id);
        $this->assertSame($branch->id, $manager->fresh()->branch_id);
    }

    public function test_assigning_new_manager_closes_old_assignment(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();
        $branch = Branch::factory()->create(['created_by' => $owner->id]);
        $m1 = User::factory()->role(Role::MANAGER)->create();
        $m2 = User::factory()->role(Role::MANAGER)->create();

        $branch->assignManager($m1, $owner);
        $branch->assignManager($m2, $owner);

        // Only one active assignment
        $this->assertSame(1, $branch->managerAssignments()->active()->count());

        // Old manager's branch_id is now null
        $this->assertNull($m1->fresh()->branch_id);

        // New manager is now bound to the branch
        $this->assertSame($branch->id, $m2->fresh()->branch_id);

        // currentManager returns the new one
        $this->assertSame($m2->id, $branch->currentManager()?->id);
    }

    // ─── unassignManager ───────────────────────────────────────
    public function test_unassign_manager_detaches_user_and_closes_assignment(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();
        $branch = Branch::factory()->create(['created_by' => $owner->id]);
        $manager = User::factory()->role(Role::MANAGER)->create();
        $branch->assignManager($manager, $owner);

        $branch->unassignManager($owner);

        $this->assertNull($branch->currentManager());
        $this->assertNull($manager->fresh()->branch_id);
        $this->assertSame(0, $branch->managerAssignments()->active()->count());
    }

    public function test_unassign_is_safe_when_no_active_assignment(): void
    {
        $branch = Branch::factory()->create();
        $branch->unassignManager(); // no exception
        $this->assertNull($branch->currentManager());
    }

    // ─── Cascade deletes ───────────────────────────────────────
    public function test_force_deleting_branch_removes_branch_manager_rows(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->role(Role::MANAGER)->create();
        $branch->assignManager($manager);

        $this->assertSame(1, BranchManager::count());

        $branch->forceDelete();

        $this->assertSame(0, BranchManager::count());
    }

    public function test_force_deleting_user_removes_branch_manager_rows(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->role(Role::MANAGER)->create();
        $branch->assignManager($manager);

        $manager->forceDelete();

        $this->assertSame(0, BranchManager::count());
    }
}