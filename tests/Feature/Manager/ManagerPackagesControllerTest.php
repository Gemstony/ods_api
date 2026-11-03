<?php

namespace Tests\Feature\Manager;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerPackagesControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        Carbon::setTestNow('2026-11-04 14:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function manager(?Branch $branch = null): User
    {
        $branch ??= Branch::factory()->create();
        return User::factory()->role(Role::MANAGER)->atBranch($branch)->create();
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
        $this->getJson('/api/v1/manager/packages')->assertStatus(401);
    }

    public function test_rejects_non_manager_roles(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();
        $this->actingAs($owner, 'api')
            ->getJson('/api/v1/manager/packages')
            ->assertStatus(403);
    }

    public function test_manager_without_branch_gets_403(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create(['branch_id' => null]);
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/packages')
            ->assertStatus(403);
    }

    // ─── Scoping ──────────────────────────────────────────────
    public function test_returns_only_own_branch_packages(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        $manager = $this->manager($own);

        $this->deliveryAt($own, [
            'status' => Delivery::STATUS_PAID,
            'tracking_id' => 'ODS-OWN-1',
        ]);
        $this->deliveryAt($other, [
            'status' => Delivery::STATUS_PAID,
            'tracking_id' => 'ODS-OTHER-1',
        ]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/packages');

        $response->assertStatus(200);
        $ids = array_column($response->json('data.data'), 'tracking_id');
        $this->assertContains('ODS-OWN-1', $ids);
        $this->assertNotContains('ODS-OTHER-1', $ids);
    }

    // ─── Status filter ────────────────────────────────────────
    public function test_includes_paid_and_batched_statuses(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);

        $this->deliveryAt($branch, ['status' => Delivery::STATUS_PAID]);
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_BATCHED]);
        // Not at branch — excluded
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_ASSIGNED]);
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_PICKED_UP]);
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_DELIVERED]);
        $this->deliveryAt($branch, ['status' => Delivery::STATUS_PENDING_PAYMENT]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/packages');

        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_excludes_send_deliveries(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);

        $this->deliveryAt($branch, [
            'status' => Delivery::STATUS_PAID,
            'service_type' => Delivery::SERVICE_RECEIVE,
        ]);
        $this->deliveryAt($branch, [
            'status' => Delivery::STATUS_PAID,
            'service_type' => Delivery::SERVICE_REGIONAL,
        ]);
        $this->deliveryAt($branch, [
            'status' => Delivery::STATUS_PAID,
            'service_type' => Delivery::SERVICE_SEND,
        ]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/packages');

        $this->assertCount(2, $response->json('data.data'));
        foreach ($response->json('data.data') as $row) {
            $this->assertNotSame('send', $row['service_type']);
        }
    }

    // ─── Aging ────────────────────────────────────────────────
    public function test_computes_hours_at_branch_from_created_at(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);

        // Created 2 hours ago
        $this->deliveryAt($branch, [
            'status' => Delivery::STATUS_PAID,
            'created_at' => '2026-11-04 12:00:00',
            'tracking_id' => 'ODS-2H',
        ]);
        // Created 26 hours ago
        $this->deliveryAt($branch, [
            'status' => Delivery::STATUS_PAID,
            'created_at' => '2026-11-03 12:00:00',
            'tracking_id' => 'ODS-26H',
        ]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/packages');

        $byTrackingId = [];
        foreach ($response->json('data.data') as $row) {
            $byTrackingId[$row['tracking_id']] = $row['hours_at_branch'];
        }
        $this->assertSame(2, $byTrackingId['ODS-2H']);
        $this->assertSame(26, $byTrackingId['ODS-26H']);
    }

    public function test_hours_at_branch_uses_created_at_for_very_fresh_packages(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);

        // Created 10 minutes ago — well under 1 hour
        $this->deliveryAt($branch, [
            'status' => Delivery::STATUS_PAID,
            'created_at' => '2026-11-04 13:50:00',
            'tracking_id' => 'ODS-FRESH-10M',
        ]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/packages');

        $row = $response->json('data.data.0');
        $this->assertSame('ODS-FRESH-10M', $row['tracking_id']);
        // Rounds down to 0 hours — never negative
        $this->assertSame(0, $row['hours_at_branch']);
    }

    // ─── Ordering ─────────────────────────────────────────────
    public function test_orders_oldest_first(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);

        $this->deliveryAt($branch, [
            'status' => Delivery::STATUS_PAID,
            'created_at' => '2026-11-04 12:00:00',
            'tracking_id' => 'ODS-NEWEST',
        ]);
        $this->deliveryAt($branch, [
            'status' => Delivery::STATUS_PAID,
            'created_at' => '2026-11-03 10:00:00',
            'tracking_id' => 'ODS-OLDEST',
        ]);
        $this->deliveryAt($branch, [
            'status' => Delivery::STATUS_PAID,
            'created_at' => '2026-11-04 09:00:00',
            'tracking_id' => 'ODS-MIDDLE',
        ]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/packages');

        $order = array_column($response->json('data.data'), 'tracking_id');
        $this->assertSame(['ODS-OLDEST', 'ODS-MIDDLE', 'ODS-NEWEST'], $order);
    }
    // ─── Items eager loaded ──────────────────────────────────
    public function test_includes_items(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $delivery = $this->deliveryAt($branch, ['status' => Delivery::STATUS_PAID]);
        DeliveryItem::factory()->for($delivery)->create([
            'product_name' => 'Salmon Oil',
            'quantity' => 2,
        ]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/packages');

        $this->assertCount(1, $response->json('data.data.0.items'));
        $this->assertSame('Salmon Oil', $response->json('data.data.0.items.0.product_name'));
    }
}