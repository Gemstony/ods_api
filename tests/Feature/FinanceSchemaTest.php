<?php

namespace Tests\Feature;

use App\Models\ClickPesaCallback;
use App\Models\Delivery;
use App\Models\Earning;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Payout;
use App\Models\PricingConfig;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceSchemaTest extends TestCase
{
    use RefreshDatabase;

    // ─── Tables ────────────────────────────────────────────────
    public function test_all_finance_tables_exist(): void
    {
        foreach ([
            'pricing_config',
            'settings',
            'payments',
            'payment_transactions',
            'clickpesa_callbacks',
            'payouts',
            'earnings',
            'expenses',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: $table");
        }
    }

    // ─── Pricing config singleton ──────────────────────────────
    public function test_pricing_config_current_creates_singleton(): void
    {
        $this->assertSame(0, PricingConfig::count());

        $a = PricingConfig::current();
        $b = PricingConfig::current();

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, PricingConfig::count());
        $this->assertSame(500.00, $a->local_rate_per_km);
        $this->assertSame(1000.00, $a->local_minimum_fee);
        $this->assertSame(80.00, $a->rider_baseline_percent);
        $this->assertSame(10.00, $a->rider_bonus_percent);
    }

    // ─── Settings key/value ────────────────────────────────────
    public function test_settings_round_trip_through_json(): void
    {
        Setting::set('batching_window_minutes', 20, 'batching');
        $this->assertSame(20, Setting::get('batching_window_minutes'));

        Setting::set('pickup_radius_km', 5.5, 'location');
        $this->assertSame(5.5, Setting::get('pickup_radius_km'));

        Setting::set('feature_flags', ['regional' => true, 'rewards' => false], 'app');
        $this->assertSame(['regional' => true, 'rewards' => false], Setting::get('feature_flags'));

        $this->assertSame('fallback', Setting::get('does_not_exist', 'fallback'));
    }

    // ─── Payments ──────────────────────────────────────────────
    public function test_payment_reference_auto_generated(): void
    {
        $p = Payment::factory()->create();
        $this->assertMatchesRegularExpression('/^PAY-\d{8}-[A-F0-9]{6}$/', $p->reference);
    }

    public function test_payment_state_helpers(): void
    {
        $completed = Payment::factory()->completed()->create();
        $failed    = Payment::factory()->failed()->create();
        $pending   = Payment::factory()->create();

        $this->assertTrue($completed->isCompleted());
        $this->assertFalse($completed->isFailed());

        $this->assertTrue($failed->isFailed());
        $this->assertTrue($pending->isPending());
    }

    public function test_delivery_cascade_deletes_payments_and_transactions(): void
    {
        $payment = Payment::factory()->create();
        PaymentTransaction::factory()->create(['payment_id' => $payment->id]);
        $delivery = $payment->delivery;

        $delivery->forceDelete();

        $this->assertSame(0, Payment::where('delivery_id', $delivery->id)->count());
        $this->assertSame(0, PaymentTransaction::where('payment_id', $payment->id)->count());
    }

    // ─── ClickPesa callback idempotency ────────────────────────
    public function test_clickpesa_callback_event_id_is_unique(): void
    {
        ClickPesaCallback::create([
            'provider_event_id' => 'EVT-001',
            'event_type'        => 'payment.success',
            'payload'           => ['x' => 1],
            'received_at'       => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ClickPesaCallback::create([
            'provider_event_id' => 'EVT-001',
            'event_type'        => 'payment.success',
            'payload'           => ['x' => 1],
            'received_at'       => now(),
        ]);
    }

    // ─── Payouts ───────────────────────────────────────────────
    public function test_payout_reference_auto_generated(): void
    {
        $p = Payout::factory()->create();
        $this->assertMatchesRegularExpression('/^PO-\d{8}-[A-F0-9]{6}$/', $p->reference);
    }

    // ─── Earnings — the structural rule ────────────────────────
    public function test_earnings_table_has_no_transport_fare_column(): void
    {
        $cols = Schema::getColumnListing('earnings');
        $this->assertNotContains('transport_fare', $cols,
            'Transport fares must never be stored in earnings — they belong in dispatcher_tickets.');
    }

    public function test_rider_and_dispatcher_earning_type_helpers(): void
    {
        $riderBaseline  = Earning::factory()->create();
        $riderBonus     = Earning::factory()->riderBonus()->create();
        $dispatcherFlat = Earning::factory()->dispatcherFlat()->create();

        $this->assertTrue($riderBaseline->isRiderEarning());
        $this->assertTrue($riderBonus->isRiderEarning());
        $this->assertTrue($dispatcherFlat->isDispatcherEarning());
    }

    public function test_earning_can_reference_payout(): void
    {
        $payout = Payout::factory()->create();
        $earning = Earning::factory()->create(['payout_id' => $payout->id]);

        $this->assertSame($payout->id, $earning->payout->id);
        $this->assertTrue($payout->earnings->contains($earning));
    }

    public function test_negative_adjustment_is_valid(): void
    {
        $adjustment = Earning::factory()->create([
            'type'   => Earning::TYPE_ADJUSTMENT,
            'amount' => -250.00,
            'notes'  => 'Clawback for cancelled delivery',
        ]);

        $this->assertSame(-250.00, $adjustment->amount);
        $this->assertTrue($adjustment->isAdjustment());
    }

    // ─── Expenses ──────────────────────────────────────────────
    public function test_expense_recorded_by_relation(): void
    {
        $owner = User::factory()->create();
        $expense = Expense::factory()->create([
            'recorded_by' => $owner->id,
            'category'    => Expense::CATEGORY_FUEL,
            'amount'      => 50000.00,
        ]);

        $this->assertSame($owner->id, $expense->recordedBy->id);
        $this->assertSame(Expense::CATEGORY_FUEL, $expense->category);
    }
}