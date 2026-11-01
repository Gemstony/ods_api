<?php

namespace Tests\Feature\Owner;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DispatcherTicket;
use App\Models\Earning;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class DashboardControllerTest extends TestCase
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

    // ─── Authorization ────────────────────────────────────────
    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/owner/dashboard')->assertStatus(401);
    }

    public function test_rejects_non_owner_roles(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/owner/dashboard')
            ->assertStatus(403);
    }

    // ─── Empty state ──────────────────────────────────────────
    public function test_returns_zeroed_kpis_when_nothing_exists(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/dashboard');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.branches.total_active', 0)
            ->assertJsonPath('data.users.pending_approvals', 0)
            ->assertJsonPath('data.users.managers', 0)
            ->assertJsonPath('data.users.riders', 0)
            ->assertJsonPath('data.users.receivers', 0)
            ->assertJsonPath('data.users.distributors', 0)
            ->assertJsonPath('data.deliveries.today_count', 0)
            ->assertJsonPath('data.deliveries.today_delivered', 0)
            ->assertJsonPath('data.deliveries.active_count', 0)
            ->assertJsonPath('data.deliveries.this_week_count', 0)
            ->assertJsonPath('data.revenue.today_tsh', 0)
            ->assertJsonPath('data.revenue.this_week_tsh', 0)
            ->assertJsonPath('data.revenue.this_month_tsh', 0)
            ->assertJsonPath('data.payables.rider_earnings_pending_tsh', 0)
            ->assertJsonPath('data.payables.dispatcher_earnings_pending_tsh', 0)
            ->assertJsonPath('data.payables.transport_fare_owed_tsh', 0)
            ->assertJsonPath('data.disputes.open_count', 0)
            ->assertJsonStructure([
                'data' => [
                    'branches', 'users', 'deliveries',
                    'revenue', 'payables', 'disputes',
                    'generated_at',
                ],
            ]);
    }

    // ─── Branches ─────────────────────────────────────────────
    public function test_counts_only_active_branches(): void
    {
        $owner = $this->owner();
        Branch::factory()->count(3)->create(['status' => 'active']);
        Branch::factory()->count(2)->create(['status' => 'inactive']);
        Branch::factory()->create(['status' => 'active', 'deleted_at' => now()]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/dashboard');

        // 3 active + 1 soft-deleted (excluded) + 2 inactive (excluded) = 3
        $response->assertJsonPath('data.branches.total_active', 3);
    }

    // ─── Users ────────────────────────────────────────────────
    public function test_counts_users_by_role_and_status(): void
    {
        $owner = $this->owner(); // active owner → counts in total_active

        User::factory()->role(Role::MANAGER)->count(2)->create();
        User::factory()->role(Role::RIDER)->count(3)->create();
        User::factory()->role(Role::RECEIVER)->count(1)->create();
        User::factory()->role(Role::DISTRIBUTOR)->count(4)->create();
        User::factory()->role(Role::DISTRIBUTOR)->pending()->count(2)->create();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/dashboard');

        $response
            ->assertJsonPath('data.users.managers', 2)
            ->assertJsonPath('data.users.riders', 3)
            ->assertJsonPath('data.users.receivers', 1)
            ->assertJsonPath('data.users.distributors', 4)
            ->assertJsonPath('data.users.pending_approvals', 2)
            // 1 owner + 2 + 3 + 1 + 4 = 11 active; 2 pending not counted
            ->assertJsonPath('data.users.total_active', 11);
    }

    // ─── Deliveries ───────────────────────────────────────────
    public function test_counts_deliveries_today_and_this_week(): void
    {
        $owner = $this->owner();
        $branch = Branch::factory()->create();

        // Today's deliveries
        Delivery::factory()->count(3)->create([
            'created_at' => now(),
            'pickup_branch_id' => $branch->id,
        ]);
        // This week but not today
        Delivery::factory()->count(5)->create([
            'created_at' => now()->subDays(3),
            'pickup_branch_id' => $branch->id,
        ]);
        // Last month
        Delivery::factory()->count(10)->create([
            'created_at' => now()->subDays(45),
            'pickup_branch_id' => $branch->id,
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/dashboard');

        $response->assertJsonPath('data.deliveries.today_count', 3);
        // this_week_count = 3 today + 5 mid-week = 8
        $response->assertJsonPath('data.deliveries.this_week_count', 8);
    }

    public function test_counts_delivered_today(): void
    {
        $owner = $this->owner();
        $branch = Branch::factory()->create();

        Delivery::factory()->count(4)->delivered()->create([
            'delivered_at' => now(),
            'pickup_branch_id' => $branch->id,
        ]);
        Delivery::factory()->count(2)->delivered()->create([
            'delivered_at' => now()->subDays(3),
            'pickup_branch_id' => $branch->id,
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/dashboard');

        $response->assertJsonPath('data.deliveries.today_delivered', 4);
    }

    public function test_counts_active_deliveries_only(): void
    {
        $owner = $this->owner();
        $branch = Branch::factory()->create();

        // Active statuses
        Delivery::factory()->count(2)->create([
            'status' => Delivery::STATUS_PAID,
            'pickup_branch_id' => $branch->id,
        ]);
        Delivery::factory()->count(3)->create([
            'status' => Delivery::STATUS_ASSIGNED,
            'pickup_branch_id' => $branch->id,
        ]);
        // Terminal statuses — not counted in active
        Delivery::factory()->count(2)->delivered()->create([
            'pickup_branch_id' => $branch->id,
        ]);
        Delivery::factory()->count(1)->create([
            'status' => Delivery::STATUS_CANCELLED,
            'pickup_branch_id' => $branch->id,
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/dashboard');

        $response->assertJsonPath('data.deliveries.active_count', 5);
    }

    // ─── Revenue ──────────────────────────────────────────────
    public function test_sums_revenue_from_completed_payments_only(): void
    {
        // Freeze the clock at a known moment so the "today", "this week",
        // and "this month" windows are deterministic regardless of when
        // the test runs.
        Carbon::setTestNow('2026-11-04 14:00:00'); // Wednesday, mid-week, mid-day

        try {
            $owner = $this->owner();
            $branch = Branch::factory()->create();
            $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();

            $delivery = Delivery::factory()->create([
                'distributor_id' => $distributor->id,
                'pickup_branch_id' => $branch->id,
            ]);

            // Today — two payments (morning + early afternoon)
            Payment::factory()->completed()->create([
                'delivery_id' => $delivery->id,
                'amount' => 2000,
                'paid_at' => '2026-11-04 10:00:00',
            ]);
            Payment::factory()->completed()->create([
                'delivery_id' => $delivery->id,
                'amount' => 3500,
                'paid_at' => '2026-11-04 12:30:00',
            ]);

            // This week but not today (Monday)
            Payment::factory()->completed()->create([
                'delivery_id' => $delivery->id,
                'amount' => 5000,
                'paid_at' => '2026-11-02 15:00:00',
            ]);

            // Last month
            Payment::factory()->completed()->create([
                'delivery_id' => $delivery->id,
                'amount' => 10000,
                'paid_at' => '2026-09-15 10:00:00',
            ]);

            // Failed — should NOT count
            Payment::factory()->failed()->create([
                'delivery_id' => $delivery->id,
                'amount' => 99999,
                'paid_at' => '2026-11-04 10:00:00',
            ]);

            $response = $this->actingAs($owner, 'api')
                ->getJson('/api/v1/owner/dashboard');

            // Today: 2000 + 3500 = 5500
            $response->assertJsonPath('data.revenue.today_tsh', 5500);
            // Week: 5500 + 5000 (Monday) = 10500
            $response->assertJsonPath('data.revenue.this_week_tsh', 10500);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ─── Payables ─────────────────────────────────────────────
    public function test_sums_rider_and_dispatcher_payables(): void
    {
        $owner = $this->owner();
        $rider = User::factory()->role(Role::RIDER)->create();
        $dispatcher = User::factory()->role(Role::RECEIVER)->create();

        // Rider baseline — pending → counts
        Earning::factory()->create([
            'user_id' => $rider->id,
            'type' => Earning::TYPE_RIDER_BASELINE,
            'amount' => 1600,
            'status' => Earning::STATUS_PENDING,
        ]);
        // Rider bonus — approved → counts
        Earning::factory()->riderBonus()->create([
            'user_id' => $rider->id,
            'status' => Earning::STATUS_APPROVED,
            'amount' => 200,
        ]);
        // Rider — already paid → doesn't count
        Earning::factory()->paid()->create([
            'user_id' => $rider->id,
            'amount' => 9999,
        ]);

        // Dispatcher — pending → counts
        Earning::factory()->dispatcherFlat()->create([
            'user_id' => $dispatcher->id,
            'status' => Earning::STATUS_PENDING,
            'amount' => 500,
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/dashboard');

        $response
            ->assertJsonPath('data.payables.rider_earnings_pending_tsh', 1800)
            ->assertJsonPath('data.payables.dispatcher_earnings_pending_tsh', 500);
    }

    public function test_sums_transport_fare_owed_to_providers(): void
    {
        $owner = $this->owner();
        $dispatcher = User::factory()->role(Role::RECEIVER)->create();

        // Unconfirmed → owed
        DispatcherTicket::factory()->create([
            'dispatcher_id' => $dispatcher->id,
            'transport_fare' => 3500,
            'provider_confirmed_at' => null,
        ]);
        DispatcherTicket::factory()->create([
            'dispatcher_id' => $dispatcher->id,
            'transport_fare' => 2500,
            'provider_confirmed_at' => null,
        ]);
        // Confirmed → already settled, doesn't count
        DispatcherTicket::factory()->create([
            'dispatcher_id' => $dispatcher->id,
            'transport_fare' => 9999,
            'provider_confirmed_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/dashboard');

        $response->assertJsonPath('data.payables.transport_fare_owed_tsh', 6000);
    }

    // ─── Disputes ─────────────────────────────────────────────
    public function test_counts_open_disputes_only(): void
    {
        $owner = $this->owner();

        \App\Models\Dispute::factory()->count(3)->create([
            'status' => \App\Models\Dispute::STATUS_OPEN,
        ]);
        \App\Models\Dispute::factory()->create([
            'status' => \App\Models\Dispute::STATUS_IN_REVIEW,
        ]);
        \App\Models\Dispute::factory()->resolved()->create();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/dashboard');

        $response->assertJsonPath('data.disputes.open_count', 4);
    }

    // ─── generated_at ─────────────────────────────────────────
    public function test_includes_generated_at_timestamp(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/dashboard');

        $generatedAt = $response->json('data.generated_at');
        $this->assertNotNull($generatedAt);
        $this->assertNotNull(\DateTime::createFromFormat(\DateTime::ATOM, $generatedAt));
    }
}