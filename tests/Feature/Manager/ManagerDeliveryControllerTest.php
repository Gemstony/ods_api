<?php

namespace Tests\Feature\Manager;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerDeliveryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
    }

    protected function manager(?Branch $branch = null): User
    {
        $branch ??= Branch::factory()->create();
        return User::factory()->role(Role::MANAGER)->atBranch($branch)->create();
    }

    protected function deliveryFor(Branch $branch, array $overrides = []): Delivery
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
        $this->getJson('/api/v1/manager/deliveries')->assertStatus(401);
    }

    public function test_rejects_non_manager_roles(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();
        $this->actingAs($owner, 'api')->getJson('/api/v1/manager/deliveries')->assertStatus(403);
    }

    // ─── index ────────────────────────────────────────────────
    public function test_index_returns_only_own_branch_deliveries(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        $manager = $this->manager($own);

        $this->deliveryFor($own, ['tracking_id' => 'ODS-OWN-1']);
        $this->deliveryFor($own, ['tracking_id' => 'ODS-OWN-2']);
        $this->deliveryFor($other, ['tracking_id' => 'ODS-OTHER-1']);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/deliveries');

        $response->assertStatus(200);
        $trackingIds = array_column($response->json('data.data'), 'tracking_id');
        $this->assertContains('ODS-OWN-1', $trackingIds);
        $this->assertContains('ODS-OWN-2', $trackingIds);
        $this->assertNotContains('ODS-OTHER-1', $trackingIds);
    }

    public function test_index_filters_by_status(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $this->deliveryFor($branch, ['status' => Delivery::STATUS_PAID]);
        $this->deliveryFor($branch, ['status' => Delivery::STATUS_DELIVERED]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/deliveries?status=paid');

        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('paid', $response->json('data.data.0.status'));
    }

    public function test_index_filters_by_service_type(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $this->deliveryFor($branch, ['service_type' => Delivery::SERVICE_RECEIVE]);
        $this->deliveryFor($branch, ['service_type' => Delivery::SERVICE_REGIONAL]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/deliveries?service_type=regional');

        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('regional', $response->json('data.data.0.service_type'));
    }

    public function test_index_includes_items(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $delivery = $this->deliveryFor($branch);
        DeliveryItem::factory()->for($delivery)->create([
            'product_name' => 'Salmon Oil',
            'product_code' => 'SLM-001',
            'quantity' => 2,
        ]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/deliveries');

        $this->assertCount(1, $response->json('data.data.0.items'));
        $this->assertSame('Salmon Oil', $response->json('data.data.0.items.0.product_name'));
    }

    // ─── show ─────────────────────────────────────────────────
    public function test_show_returns_delivery_in_own_branch(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $delivery = $this->deliveryFor($branch, ['tracking_id' => 'ODS-SHOW-1']);

        $response = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/manager/deliveries/{$delivery->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.tracking_id', 'ODS-SHOW-1');
    }

    public function test_index_filters_by_multiple_statuses(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $this->deliveryFor($branch, ['status' => Delivery::STATUS_PAID]);
        $this->deliveryFor($branch, ['status' => Delivery::STATUS_PICKED_UP]);
        $this->deliveryFor($branch, ['status' => Delivery::STATUS_DISPATCHED]);
        $this->deliveryFor($branch, ['status' => Delivery::STATUS_DELIVERED]);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/deliveries?status=paid,picked_up,dispatched');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.data'));
        $this->assertNotContains(
            'delivered',
            array_column($response->json('data.data'), 'status'),
        );
    }

    public function test_show_returns_404_for_other_branch_delivery(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        $manager = $this->manager($own);
        $delivery = $this->deliveryFor($other);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/manager/deliveries/{$delivery->id}")
            ->assertStatus(404);
    }
}