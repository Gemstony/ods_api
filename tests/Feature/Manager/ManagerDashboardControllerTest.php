<?php

namespace Tests\Feature\Manager;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        Carbon::setTestNow('2026-11-04 14:00:00'); // Wednesday mid-day
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function managerWithBranch(): array
    {
        $branch = Branch::factory()->create([
            'name' => 'Kariakoo Branch',
            'code' => 'KRK-01',
            'status' => 'active',
        ]);
        $manager = User::factory()->role(Role::MANAGER)->atBranch($branch)->create();
        return [$manager, $branch];
    }

    protected function deliveryAt(Branch $branch, array $overrides = []): Delivery
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        return Delivery::factory()->create(array_merge([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => $branch->id,
        ], $overrides));
    }

    // ─── Auth ─────────────────────────────────────────────────
    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/manager/dashboard')->assertStatus(401);
    }

    public function test_rejects_non_manager_roles(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();
        $this->actingAs($owner, 'api')
            ->getJson('/api/v1/manager/dashboard')
            ->assertStatus(403);
    }

    // ─── No branch assigned ───────────────────────────────────
    public function test_returns_empty_when_manager_has_no_branch(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create(['branch_id' => null]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/dashboard');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.branch', null)
            ->assertJsonPath('data.staff.total_active', 0)
            ->assertJsonPath('data.deliveries.waiting_pickup', 0)
            ->assertJsonPath('data.revenue.today_tsh', 0);
    }

    // ─── Empty branch ─────────────────────────────────────────
    public function test_returns_zeroed_kpis_for_empty_branch(): void
    {
        [$manager, $branch] = $this->managerWithBranch();

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/dashboard');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.branch.name', 'Kariakoo Branch')
            ->assertJsonPath('data.branch.code', 'KRK-01')
            ->assertJsonPath('data.branch.status', 'active')
            ->assertJsonPath('data.staff.total_active', 0)
            ->assertJsonPath('data.deliveries.today_count', 0)
            ->assertJsonPath('data.deliveries.active_count', 0)
            ->assertJsonPath('data.deliveries.waiting_pickup', 0)
            ->assertJsonPath('data.deliveries.in_transit', 0)
            ->assertJsonPath('data.deliveries.delivered_today', 0)
            ->assertJsonPath('data.revenue.today_tsh', 0)
            ->assertJsonStructure([
                'data' => [
                    'branch' => ['id', 'name', 'code', 'status'],
                    'staff' => ['total_active', 'receivers', 'riders'],
                    'deliveries' => [
                        'today_count', 'active_count',
                        'waiting_pickup', 'in_transit', 'delivered_today',
                    ],
                    'revenue' => ['today_tsh'],
                    'generated_at',
                ],
            ]);
    }

    // ─── Staff counts ─────────────────────────────────────────
    public function test_counts_active_staff_by_role(): void
    {
        [$manager, $branch] = $this->managerWithBranch();

        User::factory()->role(Role::RECEIVER)->atBranch($branch)->count(2)->create();
        User::factory()->role(Role::RIDER)->atBranch($branch)->count(3)->create();
        // Suspended — excluded
        User::factory()->role(Role::RIDER)->atBranch($branch)->suspended()->create();
        // Other branch — excluded
        $otherBranch = Branch::factory()->create();
        User::factory()->role(Role::RIDER)->atBranch($otherBranch)->count(2)->create();

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/dashboard');

        $response
            ->assertJsonPath('data.staff.total_active', 5)
            ->assertJsonPath('data.staff.receivers', 2)
            ->assertJsonPath('data.staff.riders', 3);
    }

    // ─── Delivery counts ──────────────────────────────────────
    public function test_counts_deliveries_scoped_to_branch(): void
    {
        [$manager, $branch] = $this->managerWithBranch();
        $otherBranch = Branch::factory()->create();

        // Own branch — 3 today, 2 yesterday
        $this->deliveryAt($branch, ['created_at' => '2026-11-04 09:00:00']);
        $this->deliveryAt($branch, ['created_at' => '2026-11-04 10:00:00']);
        $this->deliveryAt($branch, ['created_at' => '2026-11-04 11:00:00']);
        $this->deliveryAt($branch, ['created_at' => '2026-11-03 09:00:00']);
        $this->deliveryAt($branch, ['created_at' => '2026-11-02 09:00:00']);

        // Other branch — should not affect counts
        $this->deliveryAt($otherBranch, ['created_at' => '2026-11-04 09:00:00']);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/dashboard');

        $response->assertJsonPath('data.deliveries.today_count', 3);
    }

    public function test_counts_waiting_pickup_deliveries(): void
    {
        [$manager, $branch] = $this->managerWithBranch();

        // Waiting pickup = paid status
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_PAID]);
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_PAID]);
        // Not counted — already assigned
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_ASSIGNED]);
        // Not counted — pending payment
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_PENDING_PAYMENT]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/dashboard');

        $response->assertJsonPath('data.deliveries.waiting_pickup', 2);
    }

    public function test_counts_in_transit_deliveries(): void
    {
        [$manager, $branch] = $this->managerWithBranch();

        $this->deliveryAt($branch, ['status' => Delivery::STATUS_PICKED_UP]);
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_DISPATCHED]);
        // Not in transit — assigned but not picked up
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_ASSIGNED]);
        // Not in transit — already delivered
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_DELIVERED]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/dashboard');

        $response->assertJsonPath('data.deliveries.in_transit', 2);
    }

    public function test_counts_active_deliveries(): void
    {
        [$manager, $branch] = $this->managerWithBranch();

        $this->deliveryAt($branch, ['status' => Delivery::STATUS_PAID]);
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_ASSIGNED]);
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_PICKED_UP]);
        // Terminal statuses — excluded
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_DELIVERED]);
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_CANCELLED]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/dashboard');

        $response->assertJsonPath('data.deliveries.active_count', 3);
    }

    public function test_counts_delivered_today(): void
    {
        [$manager, $branch] = $this->managerWithBranch();

        $this->deliveryAt($branch, [
            'status' => Delivery::STATUS_DELIVERED,
            'delivered_at' => '2026-11-04 10:00:00',
        ]);
        $this->deliveryAt($branch, [
            'status' => Delivery::STATUS_DELIVERED,
            'delivered_at' => '2026-11-04 11:00:00',
        ]);
        // Yesterday — excluded
        $this->deliveryAt($branch, [
            'status' => Delivery::STATUS_DELIVERED,
            'delivered_at' => '2026-11-03 10:00:00',
        ]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/dashboard');

        $response->assertJsonPath('data.deliveries.delivered_today', 2);
    }

    // ─── Revenue ──────────────────────────────────────────────
    public function test_sums_revenue_today_scoped_to_branch(): void
    {
        [$manager, $branch] = $this->managerWithBranch();
        $otherBranch = Branch::factory()->create();

        $d1 = $this->deliveryAt($branch);
        $d2 = $this->deliveryAt($branch);
        $dOther = $this->deliveryAt($otherBranch);

        // Completed today at own branch — counted
        Payment::factory()->completed()->create([
            'delivery_id' => $d1->id,
            'amount' => 2000,
            'paid_at' => '2026-11-04 10:00:00',
        ]);
        Payment::factory()->completed()->create([
            'delivery_id' => $d2->id,
            'amount' => 1500,
            'paid_at' => '2026-11-04 11:00:00',
        ]);

        // Completed yesterday at own branch — excluded
        Payment::factory()->completed()->create([
            'delivery_id' => $d1->id,
            'amount' => 9999,
            'paid_at' => '2026-11-03 10:00:00',
        ]);

        // Completed today at other branch — excluded
        Payment::factory()->completed()->create([
            'delivery_id' => $dOther->id,
            'amount' => 9999,
            'paid_at' => '2026-11-04 10:00:00',
        ]);

        // Failed payment — excluded
        Payment::factory()->failed()->create([
            'delivery_id' => $d1->id,
            'amount' => 9999,
            'paid_at' => '2026-11-04 10:00:00',
        ]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/dashboard');

        $response->assertJsonPath('data.revenue.today_tsh', 3500);
    }
}