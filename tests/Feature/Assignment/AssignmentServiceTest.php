<?php

namespace Tests\Feature\Assignment;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Assignment\AssignmentService;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder)->run();
        (new PricingConfigSeeder)->run();
        (new SettingsSeeder)->run();
    }

    protected function service(): AssignmentService
    {
        return app(AssignmentService::class);
    }

    protected function branch(string $code = 'KRK-01'): Branch
    {
        return Branch::factory()->create(['code' => $code]);
    }

    protected function rider(Branch $branch, ?string $availableSince = null): User
    {
        $rider = User::factory()->role(Role::RIDER)->create([
            'branch_id' => $branch->id,
            'is_available' => true,
        ]);
        if ($availableSince !== null) {
            $rider->forceFill(['available_since' => $availableSince])->save();
            $rider->refresh();
        }

        return $rider;
    }

    protected function batchedReceive(Branch $branch, array $overrides = []): Delivery
    {
        return Delivery::factory()->create(array_merge([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_BATCHED,
            'rider_id' => null,
        ], $overrides));
    }

    // ─── No-ops ─────────────────────────────────────────────
    public function test_returns_empty_when_no_batched_deliveries(): void
    {
        $result = $this->service()->assignBatchedDeliveries();
        $this->assertSame(0, $result->assignmentsMade);
        $this->assertFalse($result->skipped);
    }

    public function test_skips_when_no_riders_available(): void
    {
        $branch = $this->branch();
        $this->batchedReceive($branch);

        $result = $this->service()->assignBatchedDeliveries();

        $this->assertSame(0, $result->assignmentsMade);
        $this->assertSame(1, $result->deliveriesUnassigned);
        $this->assertDatabaseHas('deliveries', [
            'status' => Delivery::STATUS_BATCHED,
        ]);
    }

    // ─── FIFO basics ────────────────────────────────────────
    public function test_assigns_single_delivery_to_single_rider(): void
    {
        $branch = $this->branch();
        $rider = $this->rider($branch, availableSince: now()->subMinutes(10)->toDateTimeString());
        $delivery = $this->batchedReceive($branch);

        $result = $this->service()->assignBatchedDeliveries();

        $this->assertSame(1, $result->assignmentsMade);

        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::STATUS_ASSIGNED, $fresh->status);
        $this->assertSame($rider->id, $fresh->rider_id);
        $this->assertNotNull($fresh->assigned_at);
        $this->assertNotNull($fresh->assignment_expires_at);
        $this->assertFalse($rider->fresh()->is_available);
    }

    public function test_fifo_order_by_available_since(): void
    {
        $branch = $this->branch();
        $oldest = $this->rider($branch, availableSince: now()->subMinutes(30)->toDateTimeString());
        $middle = $this->rider($branch, availableSince: now()->subMinutes(15)->toDateTimeString());
        $newest = $this->rider($branch, availableSince: now()->subMinutes(2)->toDateTimeString());

        $d1 = $this->batchedReceive($branch);
        $d2 = $this->batchedReceive($branch);
        $d3 = $this->batchedReceive($branch);

        $this->service()->assignBatchedDeliveries();

        $this->assertSame($oldest->id, $d1->fresh()->rider_id);
        $this->assertSame($middle->id, $d2->fresh()->rider_id);
        $this->assertSame($newest->id, $d3->fresh()->rider_id);
    }

    public function test_more_deliveries_than_riders_leaves_extras_batched(): void
    {
        $branch = $this->branch();
        $this->rider($branch, availableSince: now()->subMinute()->toDateTimeString());
        $d1 = $this->batchedReceive($branch);
        $d2 = $this->batchedReceive($branch);

        $result = $this->service()->assignBatchedDeliveries();

        $this->assertSame(1, $result->assignmentsMade);
        $this->assertSame(1, $result->deliveriesUnassigned);

        // Exactly one assigned, one still batched
        $statuses = Delivery::pluck('status')->sort()->values()->all();
        $this->assertContains(Delivery::STATUS_ASSIGNED, $statuses);
        $this->assertContains(Delivery::STATUS_BATCHED, $statuses);
    }

    public function test_more_riders_than_deliveries_leaves_extra_riders_available(): void
    {
        $branch = $this->branch();
        $rider1 = $this->rider($branch, availableSince: now()->subMinute()->toDateTimeString());
        $rider2 = $this->rider($branch, availableSince: now()->toDateTimeString());
        $this->batchedReceive($branch);

        $this->service()->assignBatchedDeliveries();

        // One assigned (rider1), one still available (rider2)
        $this->assertFalse($rider1->fresh()->is_available);
        $this->assertTrue($rider2->fresh()->is_available);
    }

    // ─── Branch isolation ───────────────────────────────────
    public function test_only_assigns_riders_from_the_deliverys_branch(): void
    {
        $branchA = $this->branch('KRK-01');
        $branchB = $this->branch('UPG-02');
        $riderA = $this->rider($branchA, availableSince: now()->subMinutes(5)->toDateTimeString());
        $riderB = $this->rider($branchB, availableSince: now()->subMinutes(10)->toDateTimeString());

        $delivery = $this->batchedReceive($branchA);

        $this->service()->assignBatchedDeliveries();

        $this->assertSame($riderA->id, $delivery->fresh()->rider_id);
        $this->assertTrue($riderB->fresh()->is_available, 'Rider at other branch never considered');
    }

    public function test_branch_scoped_run_ignores_other_branches(): void
    {
        $branchA = $this->branch('KRK-01');
        $branchB = $this->branch('UPG-02');
        $this->rider($branchA, availableSince: now()->subMinute()->toDateTimeString());
        $this->rider($branchB, availableSince: now()->subMinute()->toDateTimeString());

        $deliveryA = $this->batchedReceive($branchA);
        $deliveryB = $this->batchedReceive($branchB);

        $this->service()->assignBatchedDeliveries(branchId: $branchA->id);

        $this->assertSame(Delivery::STATUS_ASSIGNED, $deliveryA->fresh()->status);
        $this->assertSame(Delivery::STATUS_BATCHED, $deliveryB->fresh()->status);
    }

    // ─── Status filters ─────────────────────────────────────
    public function test_ignores_unavailable_riders(): void
    {
        $branch = $this->branch();
        $rider = $this->rider($branch);
        $rider->markBusy();
        $this->batchedReceive($branch);

        $result = $this->service()->assignBatchedDeliveries();

        $this->assertSame(0, $result->assignmentsMade);
        $this->assertSame(1, $result->deliveriesUnassigned);
    }

    public function test_ignores_non_rider_roles(): void
    {
        $branch = $this->branch();
        User::factory()->role(Role::RECEIVER)->create([
            'branch_id' => $branch->id,
            'is_available' => true,
            'available_since' => now()->subMinute(),
        ]);
        $this->batchedReceive($branch);

        $result = $this->service()->assignBatchedDeliveries();

        $this->assertSame(0, $result->assignmentsMade);
    }

    public function test_ignores_non_batched_deliveries(): void
    {
        $branch = $this->branch();
        $this->rider($branch, availableSince: now()->subMinute()->toDateTimeString());
        Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PAID,
        ]);

        $result = $this->service()->assignBatchedDeliveries();

        $this->assertSame(0, $result->assignmentsMade);
    }

    public function test_ignores_non_receive_service_types(): void
    {
        $branch = $this->branch();
        $this->rider($branch, availableSince: now()->subMinute()->toDateTimeString());
        Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_REGIONAL,
            'status' => Delivery::STATUS_BATCHED,
        ]);

        $result = $this->service()->assignBatchedDeliveries();

        $this->assertSame(0, $result->assignmentsMade);
    }

    // ─── Timeout setting ────────────────────────────────────
    public function test_uses_default_timeout_of_five_minutes(): void
    {
        $branch = $this->branch();
        $this->rider($branch, availableSince: now()->subMinute()->toDateTimeString());
        $delivery = $this->batchedReceive($branch);

        $this->service()->assignBatchedDeliveries();

        $deltaSeconds = $delivery->fresh()->assigned_at->diffInSeconds(
            $delivery->fresh()->assignment_expires_at,
        );
        $this->assertSame(300, (int) $deltaSeconds);
    }

    public function test_respects_configured_timeout(): void
    {
        Setting::set('location.assignment_timeout_minutes', 10, 'location');
        $branch = $this->branch();
        $this->rider($branch, availableSince: now()->subMinute()->toDateTimeString());
        $delivery = $this->batchedReceive($branch);

        $this->service()->assignBatchedDeliveries();

        $deltaSeconds = $delivery->fresh()->assigned_at->diffInSeconds(
            $delivery->fresh()->assignment_expires_at,
        );
        $this->assertSame(600, (int) $deltaSeconds);
    }

    // ─── Audit ──────────────────────────────────────────────
    public function test_audit_log_written_when_assignments_made(): void
    {
        $branch = $this->branch();
        $this->rider($branch, availableSince: now()->subMinute()->toDateTimeString());
        $this->batchedReceive($branch);

        $this->service()->assignBatchedDeliveries();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'assignment.run_completed',
        ]);
    }

    public function test_audit_log_not_written_when_nothing_assigned(): void
    {
        $this->service()->assignBatchedDeliveries();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'assignment.run_completed',
        ]);
    }

    public function test_audit_uses_system_actor_without_authenticated_user(): void
    {
        $branch = $this->branch();
        $this->rider($branch, availableSince: now()->subMinute()->toDateTimeString());
        $this->batchedReceive($branch);

        $this->service()->assignBatchedDeliveries();

        $log = AuditLog::where('action', 'assignment.run_completed')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->actor_id);
    }

    public function test_audit_uses_authenticated_manager_as_actor(): void
    {
        $branch = $this->branch();
        $manager = User::factory()->role(Role::MANAGER)->create([
            'branch_id' => $branch->id,
        ]);
        $this->rider($branch, availableSince: now()->subMinute()->toDateTimeString());
        $this->batchedReceive($branch);

        $this->actingAs($manager, 'api');
        $this->service()->assignBatchedDeliveries(branchId: $branch->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'assignment.run_completed',
            'actor_id' => $manager->id,
        ]);
    }
}
