<?php

namespace Tests\Feature\Owner;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\DispatcherTicket;
use App\Models\Earning;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        Carbon::setTestNow('2026-11-04 14:00:00'); // Wednesday mid-month
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function owner(): User
    {
        return User::factory()->role(Role::OWNER)->create();
    }

    protected function rider(): User
    {
        return User::factory()->role(Role::RIDER)->create();
    }

    protected function receiver(): User
    {
        return User::factory()->role(Role::RECEIVER)->create();
    }

    protected function delivery(): Delivery
    {
        $branch = Branch::factory()->create();
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        return Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => $branch->id,
        ]);
    }

    // ─── Auth ─────────────────────────────────────────────────
    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/owner/finance/summary')->assertStatus(401);
    }

    public function test_rejects_non_owner_roles(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/owner/finance/summary')
            ->assertStatus(403);
    }

    // ─── Empty state ──────────────────────────────────────────
    public function test_returns_zeroed_summary_when_empty(): void
    {
        $owner = $this->owner();
        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/finance/summary');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.revenue.today_tsh', 0)
            ->assertJsonPath('data.revenue.this_week_tsh', 0)
            ->assertJsonPath('data.revenue.this_month_tsh', 0)
            ->assertJsonPath('data.revenue.all_time_tsh', 0)
            ->assertJsonPath('data.payables.rider_pending_tsh', 0)
            ->assertJsonPath('data.payables.dispatcher_pending_tsh', 0)
            ->assertJsonPath('data.payables.transport_fare_owed_tsh', 0)
            ->assertJsonPath('data.payouts.this_month_count', 0)
            ->assertJsonPath('data.payouts.pending_count', 0)
            ->assertJsonPath('data.reconciliation.operating_surplus_tsh', 0)
            ->assertJsonPath('data.recent_payouts', [])
            ->assertJsonStructure([
                'data' => [
                    'revenue' => ['today_tsh', 'this_week_tsh', 'this_month_tsh', 'all_time_tsh'],
                    'payables' => [
                        'rider_pending_tsh', 'rider_approved_tsh',
                        'dispatcher_pending_tsh', 'dispatcher_approved_tsh',
                        'transport_fare_owed_tsh',
                    ],
                    'payouts' => ['this_month_count', 'this_month_tsh', 'pending_count', 'pending_tsh'],
                    'transport_paid' => ['this_month_tsh'],
                    'expenses' => ['this_month_tsh', 'all_time_tsh'],
                    'reconciliation' => [
                        'revenue_this_month_tsh',
                        'minus_payouts_this_month_tsh',
                        'minus_transport_paid_this_month_tsh',
                        'minus_expenses_this_month_tsh',
                        'operating_surplus_tsh',
                    ],
                    'recent_payouts',
                    'generated_at',
                ],
            ]);
    }

    // ─── Revenue ──────────────────────────────────────────────
    public function test_sums_revenue_across_windows(): void
    {
        $owner = $this->owner();
        $delivery = $this->delivery();

        // Today (Nov 4)
        Payment::factory()->completed()->create([
            'delivery_id' => $delivery->id, 'amount' => 2000,
            'paid_at' => '2026-11-04 10:00:00',
        ]);
        // Earlier this week (Nov 2)
        Payment::factory()->completed()->create([
            'delivery_id' => $delivery->id, 'amount' => 3000,
            'paid_at' => '2026-11-02 15:00:00',
        ]);
        // Earlier this month (Oct 30 — startOfMonth is Nov 1, so this is prior month)
        Payment::factory()->completed()->create([
            'delivery_id' => $delivery->id, 'amount' => 5000,
            'paid_at' => '2026-10-30 10:00:00',
        ]);
        // Failed — excluded
        Payment::factory()->failed()->create([
            'delivery_id' => $delivery->id, 'amount' => 99999,
            'paid_at' => '2026-11-04 10:00:00',
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/finance/summary');

        $response
            ->assertJsonPath('data.revenue.today_tsh', 2000)
            ->assertJsonPath('data.revenue.this_week_tsh', 5000)   // Nov 2 + Nov 4
            ->assertJsonPath('data.revenue.this_month_tsh', 5000)  // Oct 30 excluded
            ->assertJsonPath('data.revenue.all_time_tsh', 10000);  // all three
    }

    // ─── Payables ─────────────────────────────────────────────
    public function test_sums_payables_by_type_and_status(): void
    {
        $owner = $this->owner();
        $rider = $this->rider();
        $dispatcher = $this->receiver();

        // Rider pending → 1600
        Earning::factory()->create([
            'user_id' => $rider->id,
            'type' => Earning::TYPE_RIDER_BASELINE,
            'status' => Earning::STATUS_PENDING,
            'amount' => 1600,
        ]);
        // Rider approved → 200
        Earning::factory()->create([
            'user_id' => $rider->id,
            'type' => Earning::TYPE_RIDER_BONUS,
            'status' => Earning::STATUS_APPROVED,
            'amount' => 200,
        ]);
        // Rider paid → not counted
        Earning::factory()->paid()->create([
            'user_id' => $rider->id,
            'amount' => 99999,
        ]);
        // Dispatcher pending → 500
        Earning::factory()->create([
            'user_id' => $dispatcher->id,
            'type' => Earning::TYPE_DISPATCHER_FLAT,
            'status' => Earning::STATUS_PENDING,
            'amount' => 500,
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/finance/summary');

        $response
            ->assertJsonPath('data.payables.rider_pending_tsh', 1600)
            ->assertJsonPath('data.payables.rider_approved_tsh', 200)
            ->assertJsonPath('data.payables.dispatcher_pending_tsh', 500)
            ->assertJsonPath('data.payables.dispatcher_approved_tsh', 0);
    }

    public function test_sums_transport_owed_to_unconfirmed_tickets(): void
    {
        $owner = $this->owner();
        $dispatcher = $this->receiver();

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
        // Confirmed → not owed
        DispatcherTicket::factory()->create([
            'dispatcher_id' => $dispatcher->id,
            'transport_fare' => 99999,
            'provider_confirmed_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/finance/summary');

        $response->assertJsonPath('data.payables.transport_fare_owed_tsh', 6000);
    }

    // ─── Payouts ──────────────────────────────────────────────
    public function test_sums_payouts_this_month_and_pending(): void
    {
        $owner = $this->owner();
        $rider = $this->rider();

        // Paid this month → counted in this_month
        Payout::factory()->create([
            'user_id' => $rider->id,
            'amount' => 50000,
            'status' => Payout::STATUS_PAID,
            'processed_at' => '2026-11-03 10:00:00',
        ]);
        // Paid last month → all-time not relevant here but not this_month
        Payout::factory()->create([
            'user_id' => $rider->id,
            'amount' => 30000,
            'status' => Payout::STATUS_PAID,
            'processed_at' => '2026-10-15 10:00:00',
        ]);
        // Pending → counted in pending
        Payout::factory()->create([
            'user_id' => $rider->id,
            'amount' => 40000,
            'status' => Payout::STATUS_PENDING,
        ]);
        // Processing → also pending
        Payout::factory()->create([
            'user_id' => $rider->id,
            'amount' => 5000,
            'status' => Payout::STATUS_PROCESSING,
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/finance/summary');

        $response
            ->assertJsonPath('data.payouts.this_month_count', 1)
            ->assertJsonPath('data.payouts.this_month_tsh', 50000)
            ->assertJsonPath('data.payouts.pending_count', 2)
            ->assertJsonPath('data.payouts.pending_tsh', 45000);
    }

    public function test_includes_recent_payouts_capped_at_5(): void
    {
        $owner = $this->owner();
        $rider = $this->rider();

        Payout::factory()->count(7)->create([
            'user_id' => $rider->id,
            'status' => Payout::STATUS_PAID,
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/finance/summary');

        $this->assertCount(5, $response->json('data.recent_payouts'));
        $response->assertJsonStructure([
            'data' => [
                'recent_payouts' => [
                    '*' => ['id', 'user_name', 'amount', 'currency', 'status', 'reference'],
                ],
            ],
        ]);
    }

    // ─── Expenses ─────────────────────────────────────────────
    public function test_sums_expenses_this_month(): void
    {
        $owner = $this->owner();
        $recorder = User::factory()->role(Role::OWNER)->create();

        Expense::factory()->create([
            'recorded_by' => $recorder->id,
            'amount' => 50000,
            'occurred_at' => '2026-11-02 10:00:00',
        ]);
        Expense::factory()->create([
            'recorded_by' => $recorder->id,
            'amount' => 25000,
            'occurred_at' => '2026-11-03 10:00:00',
        ]);
        // Prior month → excluded from this_month
        Expense::factory()->create([
            'recorded_by' => $recorder->id,
            'amount' => 99999,
            'occurred_at' => '2026-10-15 10:00:00',
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/finance/summary');

        $response
            ->assertJsonPath('data.expenses.this_month_tsh', 75000)
            ->assertJsonPath('data.expenses.all_time_tsh', 174999);
    }

    // ─── Reconciliation ──────────────────────────────────────
    public function test_reconciliation_formula_is_correct(): void
    {
        $owner = $this->owner();
        $delivery = $this->delivery();
        $rider = $this->rider();
        $dispatcher = $this->receiver();

        // Revenue this month: 100000
        Payment::factory()->completed()->create([
            'delivery_id' => $delivery->id,
            'amount' => 100000,
            'paid_at' => '2026-11-03 10:00:00',
        ]);

        // Payouts this month: 30000
        Payout::factory()->create([
            'user_id' => $rider->id,
            'amount' => 30000,
            'status' => Payout::STATUS_PAID,
            'processed_at' => '2026-11-03 12:00:00',
        ]);

        // Transport paid this month: 15000
        DispatcherTicket::factory()->create([
            'dispatcher_id' => $dispatcher->id,
            'transport_fare' => 15000,
            'provider_confirmed_at' => '2026-11-02 08:00:00',
        ]);

        // Expenses this month: 20000
        Expense::factory()->create([
            'recorded_by' => $owner->id,
            'amount' => 20000,
            'occurred_at' => '2026-11-02 10:00:00',
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/finance/summary');

        $response
            ->assertJsonPath('data.reconciliation.revenue_this_month_tsh', 100000)
            ->assertJsonPath('data.reconciliation.minus_payouts_this_month_tsh', 30000)
            ->assertJsonPath('data.reconciliation.minus_transport_paid_this_month_tsh', 15000)
            ->assertJsonPath('data.reconciliation.minus_expenses_this_month_tsh', 20000)
            // 100000 - 30000 - 15000 - 20000 = 35000
            ->assertJsonPath('data.reconciliation.operating_surplus_tsh', 35000);
    }

    public function test_reconciliation_can_be_negative(): void
    {
        $owner = $this->owner();
        $delivery = $this->delivery();
        $rider = $this->rider();

        // Low revenue this month: 5000
        Payment::factory()->completed()->create([
            'delivery_id' => $delivery->id,
            'amount' => 5000,
            'paid_at' => '2026-11-03 10:00:00',
        ]);

        // High payouts this month: 20000
        Payout::factory()->create([
            'user_id' => $rider->id,
            'amount' => 20000,
            'status' => Payout::STATUS_PAID,
            'processed_at' => '2026-11-03 12:00:00',
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/finance/summary');

        // 5000 - 20000 = -15000
        $response->assertJsonPath('data.reconciliation.operating_surplus_tsh', -15000);
    }
}