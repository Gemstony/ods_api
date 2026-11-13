<?php

namespace Tests\Feature\Manager;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerAssignmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder)->run();
        (new PricingConfigSeeder)->run();
        (new SettingsSeeder)->run();
    }

    protected function branch(string $code = 'KRK-01'): Branch
    {
        return Branch::factory()->create(['code' => $code]);
    }

    protected function manager(Branch $branch): User
    {
        return User::factory()->role(Role::MANAGER)->create([
            'branch_id' => $branch->id,
        ]);
    }

    protected function rider(Branch $branch, ?string $since = null): User
    {
        return User::factory()->role(Role::RIDER)->create([
            'branch_id' => $branch->id,
            'is_available' => true,
            'available_since' => $since ?? now()->subMinute(),
        ]);
    }

    protected function batchedReceive(Branch $branch): Delivery
    {
        return Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_BATCHED,
            'rider_id' => null,
        ]);
    }

    // ─── Auth ───────────────────────────────────────────────
    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/manager/assignment/run')->assertStatus(401);
    }

    public function test_rejects_non_manager(): void
    {
        $rider = User::factory()->role(Role::RIDER)->create();
        $this->actingAs($rider, 'api')
            ->postJson('/api/v1/manager/assignment/run')
            ->assertStatus(403);
    }

    public function test_rejects_manager_without_branch(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create(['branch_id' => null]);
        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/assignment/run')
            ->assertStatus(403);
    }

    // ─── Happy path ─────────────────────────────────────────
    public function test_assigns_batched_deliveries_to_available_riders(): void
    {
        $branch = $this->branch();
        $manager = $this->manager($branch);
        $this->rider($branch);
        $this->batchedReceive($branch);

        $response = $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/assignment/run');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.assignments_made', 1)
            ->assertJsonPath('data.skipped', false);
    }

    public function test_does_not_touch_other_branches(): void
    {
        $branchA = $this->branch('KRK-01');
        $branchB = $this->branch('UPG-02');
        $manager = $this->manager($branchA);
        $this->rider($branchA);
        $this->rider($branchB);
        $deliveryA = $this->batchedReceive($branchA);
        $deliveryB = $this->batchedReceive($branchB);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/assignment/run')
            ->assertStatus(200);

        $this->assertSame(Delivery::STATUS_ASSIGNED, $deliveryA->fresh()->status);
        $this->assertSame(Delivery::STATUS_BATCHED, $deliveryB->fresh()->status);
    }

    public function test_returns_zero_when_no_batched_deliveries(): void
    {
        $branch = $this->branch();
        $manager = $this->manager($branch);
        $this->rider($branch);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/assignment/run')
            ->assertStatus(200)
            ->assertJsonPath('data.assignments_made', 0);
    }

    public function test_returns_zero_when_no_riders_available(): void
    {
        $branch = $this->branch();
        $manager = $this->manager($branch);
        $this->batchedReceive($branch);

        $response = $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/assignment/run');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.assignments_made', 0)
            ->assertJsonPath('data.deliveries_unassigned', 1);
    }
}
