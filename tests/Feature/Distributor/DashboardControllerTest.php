<?php

namespace Tests\Feature\Distributor;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\PricingConfig;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        Carbon::setTestNow('2026-11-04 14:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function distributor(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create();
    }

    protected function branch(): Branch
    {
        return Branch::factory()->create();
    }

    protected function deliveryFor(User $user, Branch $branch, array $overrides = []): Delivery
    {
        return Delivery::factory()->create(array_merge([
            'distributor_id' => $user->id,
            'pickup_branch_id' => $branch->id,
        ], $overrides));
    }

    // ─── Auth ─────────────────────────────────────────────────
    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/distributor/dashboard')->assertStatus(401);
    }

    public function test_rejects_non_distributor_roles(): void
    {
        foreach ([Role::OWNER, Role::MANAGER, Role::RIDER, Role::RECEIVER] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user, 'api')
                ->getJson('/api/v1/distributor/dashboard')
                ->assertStatus(403);
        }
    }

    // ─── Empty state ──────────────────────────────────────────
    public function test_returns_zeroed_kpis_for_new_distributor(): void
    {
        $distributor = $this->distributor();

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/dashboard');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deliveries.active_count', 0)
            ->assertJsonPath('data.deliveries.awaiting_payment_count', 0)
            ->assertJsonPath('data.deliveries.delivered_this_month', 0)
            ->assertJsonPath('data.deliveries.total_this_month', 0)
            ->assertJsonPath('data.revenue.spent_this_month_tsh', 0)
            ->assertJsonPath('data.rewards.progress.monthly_count', 0)
            ->assertJsonPath('data.rewards.progress.rolling_count', 0)
            ->assertJsonPath('data.rewards.thresholds.tier1', 20)
            ->assertJsonPath('data.rewards.thresholds.tier2', 50)
            ->assertJsonPath('data.rewards.thresholds.tier1_discount_percent', 50)
            ->assertJsonPath('data.recent_deliveries', [])
            ->assertJsonStructure([
                'data' => [
                    'deliveries' => [
                        'active_count', 'awaiting_payment_count',
                        'delivered_this_month', 'total_this_month',
                    ],
                    'revenue' => ['spent_this_month_tsh'],
                    'rewards' => [
                        'progress' => ['id', 'distributor_id', 'monthly_count', 'rolling_count', 'tier1_available', 'tier2_available'],
                        'thresholds' => ['tier1', 'tier2', 'tier1_discount_percent'],
                    ],
                    'recent_deliveries',
                    'generated_at',
                ],
            ]);
    }

    // ─── Delivery counts ──────────────────────────────────────
    public function test_counts_active_deliveries(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        // Active
        $this->deliveryFor($distributor, $branch, ['status' => Delivery::STATUS_PAID]);
        $this->deliveryFor($distributor, $branch, ['status' => Delivery::STATUS_ASSIGNED]);
        $this->deliveryFor($distributor, $branch, ['status' => Delivery::STATUS_PICKED_UP]);
        // Not active — pending, delivered, cancelled
        $this->deliveryFor($distributor, $branch, ['status' => Delivery::STATUS_PENDING_PAYMENT]);
        $this->deliveryFor($distributor, $branch, ['status' => Delivery::STATUS_DELIVERED]);
        $this->deliveryFor($distributor, $branch, ['status' => Delivery::STATUS_CANCELLED]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/dashboard');

        $response->assertJsonPath('data.deliveries.active_count', 3);
    }

    public function test_counts_awaiting_payment(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        $this->deliveryFor($distributor, $branch, ['status' => Delivery::STATUS_PENDING_PAYMENT]);
        $this->deliveryFor($distributor, $branch, ['status' => Delivery::STATUS_PENDING_PAYMENT]);
        $this->deliveryFor($distributor, $branch, ['status' => Delivery::STATUS_PAID]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/dashboard');

        $response->assertJsonPath('data.deliveries.awaiting_payment_count', 2);
    }

    public function test_counts_delivered_this_month(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        $this->deliveryFor($distributor, $branch, [
            'status' => Delivery::STATUS_DELIVERED,
            'delivered_at' => '2026-11-03 10:00:00',
        ]);
        $this->deliveryFor($distributor, $branch, [
            'status' => Delivery::STATUS_DELIVERED,
            'delivered_at' => '2026-11-04 10:00:00',
        ]);
        // Prior month — excluded
        $this->deliveryFor($distributor, $branch, [
            'status' => Delivery::STATUS_DELIVERED,
            'delivered_at' => '2026-10-15 10:00:00',
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/dashboard');

        $response->assertJsonPath('data.deliveries.delivered_this_month', 2);
    }

    public function test_counts_total_created_this_month(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        $this->deliveryFor($distributor, $branch, ['created_at' => '2026-11-03 10:00:00']);
        $this->deliveryFor($distributor, $branch, ['created_at' => '2026-11-04 10:00:00']);
        // Prior month — excluded
        $this->deliveryFor($distributor, $branch, ['created_at' => '2026-10-15 10:00:00']);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/dashboard');

        $response->assertJsonPath('data.deliveries.total_this_month', 2);
    }

    // ─── Spend ────────────────────────────────────────────────
    public function test_sums_spent_this_month_excluding_pending_and_refunded(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        // Paid and in progress — counted
        $this->deliveryFor($distributor, $branch, [
            'status' => Delivery::STATUS_PAID,
            'total_amount' => 2000,
            'created_at' => '2026-11-03 10:00:00',
        ]);
        // Delivered — counted
        $this->deliveryFor($distributor, $branch, [
            'status' => Delivery::STATUS_DELIVERED,
            'total_amount' => 3500,
            'delivered_at' => '2026-11-03 12:00:00',
            'created_at' => '2026-11-03 10:00:00',
        ]);
        // Pending — NOT counted
        $this->deliveryFor($distributor, $branch, [
            'status' => Delivery::STATUS_PENDING_PAYMENT,
            'total_amount' => 99999,
            'created_at' => '2026-11-03 10:00:00',
        ]);
        // Cancelled — NOT counted (refunded)
        $this->deliveryFor($distributor, $branch, [
            'status' => Delivery::STATUS_CANCELLED,
            'total_amount' => 99999,
            'created_at' => '2026-11-03 10:00:00',
        ]);
        // Prior month — NOT counted
        $this->deliveryFor($distributor, $branch, [
            'status' => Delivery::STATUS_PAID,
            'total_amount' => 99999,
            'created_at' => '2026-10-15 10:00:00',
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/dashboard');

        $response->assertJsonPath('data.revenue.spent_this_month_tsh', 5500);
    }

    // ─── Scoping ──────────────────────────────────────────────
    public function test_does_not_include_other_distributors_deliveries(): void
    {
        $distributor = $this->distributor();
        $other = $this->distributor();
        $branch = $this->branch();

        $this->deliveryFor($distributor, $branch, ['status' => Delivery::STATUS_PAID]);
        $this->deliveryFor($other, $branch, ['status' => Delivery::STATUS_PAID]);
        $this->deliveryFor($other, $branch, ['status' => Delivery::STATUS_PAID]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/dashboard');

        $response->assertJsonPath('data.deliveries.active_count', 1);
    }

    // ─── Rewards ──────────────────────────────────────────────
    public function test_includes_rewards_progress_and_thresholds(): void
    {
        $distributor = $this->distributor();

        // Override thresholds to verify they flow through
        PricingConfig::current()->update([
            'reward_tier1_threshold' => 15,
            'reward_tier2_threshold' => 40,
            'reward_tier1_discount_percent' => 60,
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/dashboard');

        $response
            ->assertJsonPath('data.rewards.thresholds.tier1', 15)
            ->assertJsonPath('data.rewards.thresholds.tier2', 40)
            ->assertJsonPath('data.rewards.thresholds.tier1_discount_percent', 60);
    }

    // ─── Recent deliveries ────────────────────────────────────
    public function test_returns_last_three_deliveries(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();

        for ($i = 1; $i <= 5; $i++) {
            $this->deliveryFor($distributor, $branch, [
                'created_at' => "2026-11-0{$i} 10:00:00",
                'tracking_id' => "ODS-2026-{$i}",
            ]);
        }

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/dashboard');

        $this->assertCount(3, $response->json('data.recent_deliveries'));
        // Newest first: Nov 5, Nov 4, Nov 3
        $this->assertSame('ODS-2026-5', $response->json('data.recent_deliveries.0.tracking_id'));
        $this->assertSame('ODS-2026-4', $response->json('data.recent_deliveries.1.tracking_id'));
        $this->assertSame('ODS-2026-3', $response->json('data.recent_deliveries.2.tracking_id'));
    }

    public function test_recent_deliveries_includes_items(): void
    {
        $distributor = $this->distributor();
        $branch = $this->branch();
        $d = $this->deliveryFor($distributor, $branch);
        DeliveryItem::factory()->for($d)->create([
            'product_name' => 'Salmon Oil',
            'quantity' => 2,
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/dashboard');

        $this->assertCount(1, $response->json('data.recent_deliveries.0.items'));
        $this->assertSame('Salmon Oil', $response->json('data.recent_deliveries.0.items.0.product_name'));
    }
}