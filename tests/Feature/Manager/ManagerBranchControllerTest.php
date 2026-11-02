<?php

namespace Tests\Feature\Manager;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerBranchControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
    }

    protected function manager(?Branch $branch = null): User
    {
        $branch ??= Branch::factory()->create();
        return User::factory()->role(Role::MANAGER)->atBranch($branch)->create();
    }

    // ─── Auth ─────────────────────────────────────────────────
    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/manager/branch')->assertStatus(401);
        $this->putJson('/api/v1/manager/branch', [])->assertStatus(401);
    }

    public function test_rejects_non_manager_roles(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();
        $this->actingAs($owner, 'api')
            ->getJson('/api/v1/manager/branch')
            ->assertStatus(403);

        $rider = User::factory()->role(Role::RIDER)->create();
        $this->actingAs($rider, 'api')
            ->getJson('/api/v1/manager/branch')
            ->assertStatus(403);
    }

    public function test_manager_without_branch_gets_403(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create(['branch_id' => null]);
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/branch')
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your account is not assigned to a branch.']);
    }

    // ─── show ─────────────────────────────────────────────────
    public function test_show_returns_own_branch(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'My Branch',
            'code' => 'MY-01',
        ]);
        $manager = $this->manager($branch);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/branch');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.id', $branch->id)
            ->assertJsonPath('data.name', 'My Branch')
            ->assertJsonPath('data.code', 'MY-01');
    }

    public function test_show_does_not_leak_other_branches(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        $manager = $this->manager($own);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/branch');

        $response->assertStatus(200);
        $this->assertSame($own->id, $response->json('data.id'));
        $this->assertNotSame($other->id, $response->json('data.id'));
    }

    // ─── update ───────────────────────────────────────────────
    public function test_update_changes_editable_fields(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'Old Name',
            'code' => 'KEEP-ME',
            'status' => 'active',
        ]);
        $manager = $this->manager($branch);

        $response = $this->actingAs($manager, 'api')
            ->putJson('/api/v1/manager/branch', [
                'name' => 'New Name',
                'address' => '456 New Street',
                'latitude' => -6.85,
                'longitude' => 39.25,
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.address', '456 New Street');

        $fresh = $branch->fresh();
        $this->assertSame('New Name', $fresh->name);
        // Code unchanged
        $this->assertSame('KEEP-ME', $fresh->code);
        // Status unchanged
        $this->assertSame('active', $fresh->status);
    }

      public function test_update_cannot_change_code(): void
    {
        $branch = Branch::factory()->create(['code' => 'ORIG-01']);
        $manager = $this->manager($branch);

        // Valid base payload (address must be 5+ chars per FormRequest),
        // plus an extra `code` field the request should silently ignore.
        $this->actingAs($manager, 'api')
            ->putJson('/api/v1/manager/branch', [
                'name' => 'Branch',
                'address' => 'Valid Address',
                'latitude' => 0,
                'longitude' => 0,
                'code' => 'HACK-99', // no rule allows this — must be ignored
            ])
            ->assertStatus(200);

        $this->assertSame('ORIG-01', $branch->fresh()->code);
    }

       public function test_update_cannot_change_status(): void
    {
        $branch = Branch::factory()->create(['status' => 'active']);
        $manager = $this->manager($branch);

        // Valid base payload + extra `status` field the request ignores.
        $this->actingAs($manager, 'api')
            ->putJson('/api/v1/manager/branch', [
                'name' => 'Branch',
                'address' => 'Valid Address',
                'latitude' => 0,
                'longitude' => 0,
                'status' => 'inactive', // no rule allows this — must be ignored
            ])
            ->assertStatus(200);

        $this->assertSame('active', $branch->fresh()->status);
    }

    public function test_update_validates_coordinates(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'api')
            ->putJson('/api/v1/manager/branch', [
                'name' => 'X',
                'address' => 'Y',
                'latitude' => 100,
                'longitude' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['latitude']]);
    }
}