<?php

namespace Tests\Feature\Compensation;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Earning;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Compensation\CompensationService;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompensationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        (new SettingsSeeder())->run();
    }

    protected function service(): CompensationService
    {
        return app(CompensationService::class);
    }

    protected function deliveredLocalDelivery(array $overrides = []): Delivery
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $rider = User::factory()->role(Role::RIDER)->create();
        $branch = Branch::factory()->create();

        return Delivery::factory()->create(array_merge([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => $branch->id,
            'rider_id' => $rider->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_DELIVERED,
            'delivered_at' => now()->subMinutes(5),
            'ods_delivery_fee' => 5000,
            'discount_amount' => 0,
            'total_amount' => 5000,
            'currency' => 'TZS',
            'batch_id' => null,
        ], $overrides));
    }

    protected function deliveredRegionalDelivery(array $overrides = []): Delivery
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $dispatcher = User::factory()->role(Role::RECEIVER)->create([
            'dispatcher_enabled' => true,
        ]);
        $branch = Branch::factory()->create();

        return Delivery::factory()->create(array_merge([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => $branch->id,
            'dispatcher_id' => $dispatcher->id,
            'service_type' => Delivery::SERVICE_REGIONAL,
            'status' => Delivery::STATUS_DELIVERED,
            'delivered_at' => now()->subMinutes(5),
            'ods_delivery_fee' => 2000,
            'bus_cargo_fare' => 3500,
            'discount_amount' => 0,
            'total_amount' => 5500,
            'currency' => 'TZS',
        ], $overrides));
    }

    // ─── Skip conditions ─────────────────────────────────────
    public function test_skips_when_delivery_not_delivered(): void
    {
        $delivery = $this->deliveredLocalDelivery(['status' => Delivery::STATUS_PICKED_UP]);

        $result = $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertSame(0, $result->awardedCount);
        $this->assertTrue($result->skipped());
        $this->assertSame(0, Earning::count());
    }

    public function test_skips_when_delivered_at_missing(): void
    {
        $delivery = $this->deliveredLocalDelivery(['delivered_at' => null]);

        $result = $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertSame(0, $result->awardedCount);
        $this->assertStringContainsString('no delivered_at', (string) $result->skipReason);
    }

    public function test_skips_when_compensation_disabled(): void
    {
        Setting::set('compensation.enabled', false, 'compensation');
        $delivery = $this->deliveredLocalDelivery();

        $result = $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertSame(0, $result->awardedCount);
        $this->assertStringContainsString('disabled', (string) $result->skipReason);
    }

    // ─── Local: rider baseline ───────────────────────────────
    public function test_local_delivery_creates_rider_baseline(): void
    {
        $delivery = $this->deliveredLocalDelivery();

        $result = $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertSame(1, $result->awardedCount);
        $this->assertDatabaseHas('earnings', [
            'delivery_id' => $delivery->id,
            'user_id' => $delivery->rider_id,
            'type' => Earning::TYPE_RIDER_BASELINE,
            'status' => Earning::STATUS_PENDING,
        ]);
        $earning = Earning::first();
        $this->assertSame(4000.0, (float) $earning->amount); // 80% of 5000
    }

    public function test_local_delivery_uses_post_discount_fee(): void
    {
        // Basis = ods_delivery_fee (5000). Discount is applied to ODS revenue,
        // not to rider compensation. Ensures rider pay is predictable.
        $delivery = $this->deliveredLocalDelivery(['discount_amount' => 1000]);

        $this->service()->awardForDeliveredDelivery($delivery);

        $earning = Earning::where('type', Earning::TYPE_RIDER_BASELINE)->first();
        $this->assertSame(4000.0, (float) $earning->amount); // still 80% of 5000
    }

    public function test_local_delivery_no_baseline_when_fee_zero(): void
    {
        $delivery = $this->deliveredLocalDelivery(['ods_delivery_fee' => 0]);

        $result = $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertSame(0, $result->awardedCount);
    }

    // ─── Local: rider bonus (opt-in) ─────────────────────────
    public function test_no_bonus_by_default(): void
    {
        $delivery = $this->deliveredLocalDelivery(['batch_id' => 'BATCH-1']);
        Delivery::factory()->create([
            'batch_id' => 'BATCH-1',
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_DELIVERED,
        ]);

        $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertSame(0, Earning::where('type', Earning::TYPE_RIDER_BONUS)->count());
    }

    public function test_bonus_awarded_when_enabled_and_batch_has_multiple(): void
    {
        Setting::set('compensation.rider_bonus_enabled', true, 'compensation');
        $delivery = $this->deliveredLocalDelivery(['batch_id' => 'BATCH-1']);
        Delivery::factory()->create([
            'batch_id' => 'BATCH-1',
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_DELIVERED,
        ]);

        $result = $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertSame(2, $result->awardedCount);
        $bonus = Earning::where('type', Earning::TYPE_RIDER_BONUS)->first();
        $this->assertNotNull($bonus);
        $this->assertSame(500.0, (float) $bonus->amount); // 10% of 5000
    }

    public function test_no_bonus_when_batch_size_is_one(): void
    {
        Setting::set('compensation.rider_bonus_enabled', true, 'compensation');
        $delivery = $this->deliveredLocalDelivery(['batch_id' => 'BATCH-SOLO']);

        $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertSame(0, Earning::where('type', Earning::TYPE_RIDER_BONUS)->count());
    }

    // ─── Regional: flat model ────────────────────────────────
    public function test_regional_delivery_creates_dispatcher_flat(): void
    {
        $delivery = $this->deliveredRegionalDelivery();

        $result = $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertSame(1, $result->awardedCount);
        $earning = Earning::where('type', Earning::TYPE_DISPATCHER_FLAT)->first();
        $this->assertNotNull($earning);
        $this->assertSame(2000.0, (float) $earning->amount); // default flat
        $this->assertSame($delivery->dispatcher_id, $earning->user_id);
    }

    // ─── Regional: percent model ─────────────────────────────
    public function test_regional_delivery_creates_dispatcher_percent(): void
    {
        Setting::set('compensation.dispatcher_model', 'percent', 'compensation');
        Setting::set('compensation.dispatcher_percent', 15, 'compensation');
        $delivery = $this->deliveredRegionalDelivery();

        $this->service()->awardForDeliveredDelivery($delivery);

        $earning = Earning::where('type', Earning::TYPE_DISPATCHER_PERCENT)->first();
        $this->assertNotNull($earning);
        $this->assertSame(300.0, (float) $earning->amount); // 15% of 2000
    }

    // ─── Regional: none model ────────────────────────────────
    public function test_regional_delivery_no_earning_when_model_none(): void
    {
        Setting::set('compensation.dispatcher_model', 'none', 'compensation');
        $delivery = $this->deliveredRegionalDelivery();

        $result = $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertSame(0, $result->awardedCount);
    }

    // ─── Idempotency ─────────────────────────────────────────
    public function test_idempotent_second_call_creates_nothing(): void
    {
        $delivery = $this->deliveredLocalDelivery();

        $first = $this->service()->awardForDeliveredDelivery($delivery);
        $second = $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertSame(1, $first->awardedCount);
        $this->assertSame(0, $second->awardedCount);
        $this->assertSame(1, Earning::count());
    }

    public function test_idempotent_regional_second_call_creates_nothing(): void
    {
        $delivery = $this->deliveredRegionalDelivery();

        $first = $this->service()->awardForDeliveredDelivery($delivery);
        $second = $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertSame(1, $first->awardedCount);
        $this->assertSame(0, $second->awardedCount);
        $this->assertSame(1, Earning::count());
    }

    // ─── Approval gate ───────────────────────────────────────
    public function test_initial_status_is_approved_when_approval_not_required(): void
    {
        Setting::set('compensation.require_approval', false, 'compensation');
        $delivery = $this->deliveredLocalDelivery();

        $this->service()->awardForDeliveredDelivery($delivery);

        $earning = Earning::first();
        $this->assertSame(Earning::STATUS_APPROVED, $earning->status);
    }

    public function test_initial_status_is_pending_by_default(): void
    {
        $delivery = $this->deliveredLocalDelivery();

        $this->service()->awardForDeliveredDelivery($delivery);

        $earning = Earning::first();
        $this->assertSame(Earning::STATUS_PENDING, $earning->status);
    }

    // ─── Audit ───────────────────────────────────────────────
    public function test_audit_log_written_when_earnings_awarded(): void
    {
        $delivery = $this->deliveredLocalDelivery();

        $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'compensation.awarded',
        ]);
    }

    public function test_audit_log_not_written_when_no_earnings(): void
    {
        $delivery = $this->deliveredLocalDelivery(['ods_delivery_fee' => 0]);

        $this->service()->awardForDeliveredDelivery($delivery);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'compensation.awarded',
        ]);
    }
}