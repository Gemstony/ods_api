<?php

namespace Tests\Feature\Manager;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerBatchingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        (new SettingsSeeder())->run();
    }

    protected function manager(?Branch $branch = null): User
    {
        return User::factory()->role(Role::MANAGER)->create([
            'branch_id' => $branch?->id,
        ]);
    }

    protected function branch(string $code = 'KRK-01'): Branch
    {
        return Branch::factory()->create(['code' => $code]);
    }

    protected function receivePaid(Branch $branch): Delivery
    {
        return Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PAID,
        ]);
    }

    // ─── Auth ───────────────────────────────────────────────
    public function test_run_requires_authentication(): void
    {
        $this->postJson('/api/v1/manager/batching/run')->assertStatus(401);
    }

    public function test_run_rejects_non_manager_roles(): void
    {
        $rider = User::factory()->role(Role::RIDER)->create();
        $this->actingAs($rider, 'api')
            ->postJson('/api/v1/manager/batching/run')
            ->assertStatus(403);
    }

    public function test_run_rejects_manager_without_branch(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/batching/run')
            ->assertStatus(403);
    }

    // ─── Happy path ─────────────────────────────────────────
    public function test_run_batches_own_branch_deliveries(): void
    {
        $branch = $this->branch();
        $manager = $this->manager($branch);
        $this->receivePaid($branch);

        $response = $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/batching/run');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.batches_created', 1)
            ->assertJsonPath('data.deliveries_batched', 1)
            ->assertJsonPath('data.skipped', false);

        $this->assertDatabaseHas('deliveries', [
            'pickup_branch_id' => $branch->id,
            'status' => Delivery::STATUS_BATCHED,
        ]);
    }

    public function test_run_returns_zero_when_no_paid_deliveries(): void
    {
        $branch = $this->branch();
        $manager = $this->manager($branch);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/batching/run')
            ->assertStatus(200)
            ->assertJsonPath('data.batches_created', 0)
            ->assertJsonPath('data.deliveries_batched', 0);
    }

    public function test_run_does_not_touch_other_branches(): void
    {
        $branchA = $this->branch('KRK-01');
        $branchB = $this->branch('UPG-02');
        $manager = $this->manager($branchA);
        $this->receivePaid($branchA);
        $otherDelivery = $this->receivePaid($branchB);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/batching/run')
            ->assertStatus(200);

        $this->assertSame(
            Delivery::STATUS_PAID,
            $otherDelivery->fresh()->status,
        );
    }

    public function test_run_returns_skipped_when_batching_disabled(): void
    {
        $branch = $this->branch();
        $manager = $this->manager($branch);
        $this->receivePaid($branch);
        Setting::set('batching.enabled', false, 'batching');

        $response = $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/batching/run');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.skipped', true)
            ->assertJsonPath('data.batches_created', 0);
    }
}