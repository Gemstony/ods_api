<?php

namespace Tests\Feature\Batching;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Setting;
use App\Services\Batching\BatchingService;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        (new SettingsSeeder())->run();
    }

    protected function service(): BatchingService
    {
        return app(BatchingService::class);
    }

    protected function branch(string $code = 'KRK-01'): Branch
    {
        return Branch::factory()->create(['code' => $code]);
    }

    protected function receivePaid(Branch $branch, array $overrides = []): Delivery
    {
        return Delivery::factory()->create(array_merge([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PAID,
        ], $overrides));
    }

    protected function regionalPaid(Branch $branch, array $overrides = []): Delivery
    {
        return Delivery::factory()->create(array_merge([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_REGIONAL,
            'status' => Delivery::STATUS_PAID,
            'bus_cargo_fare' => 3500,
        ], $overrides));
    }

    protected function sendPaid(float $lat = -6.79, float $lng = 39.20, array $overrides = []): Delivery
    {
        return Delivery::factory()->create(array_merge([
            'pickup_branch_id' => null,
            'service_type' => Delivery::SERVICE_SEND,
            'status' => Delivery::STATUS_PAID,
            'destination_latitude' => $lat,
            'destination_longitude' => $lng,
        ], $overrides));
    }

    // ─── Toggle ─────────────────────────────────────────────
    public function test_returns_skipped_when_batching_disabled(): void
    {
        Setting::set('batching.enabled', false, 'batching');
        $branch = $this->branch();
        $this->receivePaid($branch);

        $result = $this->service()->batchPendingDeliveries();

        $this->assertTrue($result->skipped);
        $this->assertSame('Batching is disabled.', $result->skipReason);
        $this->assertSame(0, $result->batchesCreated);
        $this->assertSame(0, $result->deliveriesBatched);
    }

    // ─── No-ops ─────────────────────────────────────────────
    public function test_returns_empty_when_no_paid_deliveries(): void
    {
        $result = $this->service()->batchPendingDeliveries();

        $this->assertFalse($result->skipped);
        $this->assertSame(0, $result->batchesCreated);
        $this->assertSame(0, $result->deliveriesBatched);
    }

    public function test_ignores_already_batched_deliveries(): void
    {
        $branch = $this->branch();
        Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_BATCHED,
            'batch_id' => 'EXISTING-BATCH',
        ]);

        $result = $this->service()->batchPendingDeliveries();

        $this->assertSame(0, $result->batchesCreated);
        $this->assertSame(0, $result->deliveriesBatched);
    }

    public function test_ignores_deliveries_in_other_statuses(): void
    {
        $branch = $this->branch();
        Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PENDING_PAYMENT,
        ]);

        $result = $this->service()->batchPendingDeliveries();

        $this->assertSame(0, $result->batchesCreated);
    }

    // ─── Receive / Regional grouping ────────────────────────
    public function test_batches_single_receive_delivery(): void
    {
        $branch = $this->branch();
        $delivery = $this->receivePaid($branch);

        $result = $this->service()->batchPendingDeliveries();

        $this->assertSame(1, $result->batchesCreated);
        $this->assertSame(1, $result->deliveriesBatched);

        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::STATUS_BATCHED, $fresh->status);
        $this->assertNotNull($fresh->batch_id);
        $this->assertStringStartsWith('BATCH-KRK-01-', $fresh->batch_id);
        $this->assertNotNull($fresh->batch_window_start);
        $this->assertNotNull($fresh->batch_window_end);
    }

    public function test_groups_receive_deliveries_by_branch(): void
    {
        $branchA = $this->branch('KRK-01');
        $branchB = $this->branch('UPG-02');
        $this->receivePaid($branchA);
        $this->receivePaid($branchA);
        $this->receivePaid($branchB);

        $result = $this->service()->batchPendingDeliveries();

        $this->assertSame(2, $result->batchesCreated);
        $this->assertSame(3, $result->deliveriesBatched);
        $this->assertCount(2, $result->batchIds);
    }

    public function test_receive_and_regional_get_separate_batches_even_from_same_branch(): void
    {
        $branch = $this->branch();
        $this->receivePaid($branch);
        $this->regionalPaid($branch);

        $result = $this->service()->batchPendingDeliveries();

        $this->assertSame(2, $result->batchesCreated);
        $this->assertSame(2, $result->deliveriesBatched);
        $hasReceiveBatch = collect($result->batchIds)->contains(fn ($id) => ! str_contains($id, 'REG'));
        $hasRegionalBatch = collect($result->batchIds)->contains(fn ($id) => str_contains($id, 'REG'));
        $this->assertTrue($hasReceiveBatch);
        $this->assertTrue($hasRegionalBatch);
    }

    // ─── Send grouping ──────────────────────────────────────
    public function test_sends_in_same_geo_bucket_group_together(): void
    {
        $this->sendPaid(-6.79, 39.20);
        $this->sendPaid(-6.81, 39.21);

        $result = $this->service()->batchPendingDeliveries();

        $this->assertSame(1, $result->batchesCreated);
        $this->assertSame(2, $result->deliveriesBatched);
    }

    public function test_sends_in_different_geo_buckets_form_separate_batches(): void
    {
        $this->sendPaid(-6.79, 39.20);
        $this->sendPaid(-7.50, 39.80);

        $result = $this->service()->batchPendingDeliveries();

        $this->assertSame(2, $result->batchesCreated);
        $this->assertSame(2, $result->deliveriesBatched);
    }

    public function test_send_without_gps_is_skipped(): void
    {
        Delivery::factory()->create([
            'pickup_branch_id' => null,
            'service_type' => Delivery::SERVICE_SEND,
            'status' => Delivery::STATUS_PAID,
            'destination_latitude' => null,
            'destination_longitude' => null,
        ]);

        $result = $this->service()->batchPendingDeliveries();

        $this->assertSame(0, $result->batchesCreated);
        $this->assertSame(0, $result->deliveriesBatched);
        $this->assertSame(1, $result->deliveriesSkipped);
    }

    public function test_receive_without_branch_is_skipped(): void
    {
        Delivery::factory()->create([
            'pickup_branch_id' => null,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PAID,
        ]);

        $result = $this->service()->batchPendingDeliveries();

        $this->assertSame(0, $result->batchesCreated);
        $this->assertSame(1, $result->deliveriesSkipped);
    }

    // ─── Window ─────────────────────────────────────────────
    public function test_window_end_is_start_plus_ten_minutes_by_default(): void
    {
        $branch = $this->branch();
        $delivery = $this->receivePaid($branch);

        $result = $this->service()->batchPendingDeliveries();

        $fresh = $delivery->fresh();
        $this->assertNotNull($fresh->batch_window_start);
        $this->assertNotNull($fresh->batch_window_end);

        // Carbon 3's diffInSeconds() is directional — start->diff(end) is
        // positive when end > start.
        $deltaSeconds = $fresh->batch_window_start->diffInSeconds(
            $fresh->batch_window_end,
        );
        $this->assertSame(600, (int) $deltaSeconds, 'Default window is 10 min');
    }

    public function test_window_respects_configured_minutes(): void
    {
        Setting::set('batching.window_minutes', 5, 'batching');
        $branch = $this->branch();
        $delivery = $this->receivePaid($branch);

        $this->service()->batchPendingDeliveries();

        $fresh = $delivery->fresh();
        $this->assertSame(
            300,
            (int) $fresh->batch_window_start->diffInSeconds(
                $fresh->batch_window_end,
            ),
        );
    }

    // ─── Branch scoping (Manager button) ────────────────────
    public function test_branch_scoped_run_ignores_other_branches(): void
    {
        $branchA = $this->branch('KRK-01');
        $branchB = $this->branch('UPG-02');
        $deliveryA = $this->receivePaid($branchA);
        $deliveryB = $this->receivePaid($branchB);

        $result = $this->service()->batchPendingDeliveries(branchId: $branchA->id);

        $this->assertSame(1, $result->batchesCreated);
        $this->assertSame(1, $result->deliveriesBatched);

        $this->assertSame(Delivery::STATUS_BATCHED, $deliveryA->fresh()->status);
        $this->assertSame(Delivery::STATUS_PAID, $deliveryB->fresh()->status);
    }

    public function test_branch_scoped_run_never_touches_sends(): void
    {
        $branch = $this->branch();
        $this->sendPaid(-6.79, 39.20);

        $result = $this->service()->batchPendingDeliveries(branchId: $branch->id);

        $this->assertSame(0, $result->batchesCreated);
    }

    // ─── Audit ──────────────────────────────────────────────
    public function test_audit_log_written_when_batches_created(): void
    {
        $branch = $this->branch();
        $this->receivePaid($branch);

        $this->service()->batchPendingDeliveries();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'batching.run_completed',
        ]);
    }

    public function test_audit_log_uses_system_actor_when_no_user_authenticated(): void
    {
        $branch = $this->branch();
        $this->receivePaid($branch);

        $this->service()->batchPendingDeliveries();

        $log = AuditLog::where('action', 'batching.run_completed')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->actor_id, 'System-originated batch run has no actor');
    }

    public function test_audit_log_uses_authenticated_user_as_actor(): void
    {
        $branch = $this->branch();
        $manager = \App\Models\User::factory()
            ->role(\App\Models\Role::MANAGER)
            ->create(['branch_id' => $branch->id]);
        $this->receivePaid($branch);

        $this->actingAs($manager, 'api');
        $this->service()->batchPendingDeliveries(branchId: $branch->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'batching.run_completed',
            'actor_id' => $manager->id,
        ]);
    }

    public function test_audit_log_not_written_when_nothing_batched(): void
    {
        $result = $this->service()->batchPendingDeliveries();

        $this->assertSame(0, $result->batchesCreated);
        $this->assertSame(
            0,
            AuditLog::where('action', 'batching.run_completed')->count(),
        );
    }
}