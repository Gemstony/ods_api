<?php

namespace Tests\Feature\Rider;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderDeliveryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
    }

    protected function rider(): User
    {
        return User::factory()->role(Role::RIDER)->create();
    }

    protected function assignedDelivery(User $rider, string $status = Delivery::STATUS_ASSIGNED): Delivery
    {
        return Delivery::factory()->create([
            'rider_id' => $rider->id,
            'status' => $status,
            'assigned_at' => now(),
        ]);
    }

    // ─── Auth ─────────────────────────────────────────────────
    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/rider/deliveries')->assertStatus(401);
    }

    public function test_index_rejects_other_roles(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/rider/deliveries')
            ->assertStatus(403);

        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/rider/deliveries')
            ->assertStatus(403);
    }

    // ─── index ────────────────────────────────────────────────
    public function test_index_returns_only_own_deliveries(): void
    {
        $rider = $this->rider();
        $other = $this->rider();

        $this->assignedDelivery($rider);
        $this->assignedDelivery($other);

        $response = $this->actingAs($rider, 'api')
            ->getJson('/api/v1/rider/deliveries');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_index_filters_by_status(): void
    {
        $rider = $this->rider();
        $this->assignedDelivery($rider, Delivery::STATUS_ASSIGNED);
        $this->assignedDelivery($rider, Delivery::STATUS_DELIVERED);

        $response = $this->actingAs($rider, 'api')
            ->getJson('/api/v1/rider/deliveries?status=assigned');

        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('assigned', $response->json('data.data.0.status'));
    }

    public function test_index_supports_comma_separated_statuses(): void
    {
        $rider = $this->rider();
        $this->assignedDelivery($rider, Delivery::STATUS_ACCEPTED);
        $this->assignedDelivery($rider, Delivery::STATUS_PICKED_UP);
        $this->assignedDelivery($rider, Delivery::STATUS_DELIVERED);

        $response = $this->actingAs($rider, 'api')
            ->getJson('/api/v1/rider/deliveries?status=accepted,picked_up');

        $this->assertCount(2, $response->json('data.data'));
    }

    // ─── show ─────────────────────────────────────────────────
    public function test_show_returns_own_delivery(): void
    {
        $rider = $this->rider();
        $delivery = $this->assignedDelivery($rider);

        $response = $this->actingAs($rider, 'api')
            ->getJson("/api/v1/rider/deliveries/{$delivery->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.id', $delivery->id)
            ->assertJsonPath('data.available_actions', ['accept']);
    }

    public function test_show_returns_404_for_other_riders_delivery(): void
    {
        $rider = $this->rider();
        $other = $this->rider();
        $delivery = $this->assignedDelivery($other);

        $this->actingAs($rider, 'api')
            ->getJson("/api/v1/rider/deliveries/{$delivery->id}")
            ->assertStatus(404);
    }

    // ─── accept ───────────────────────────────────────────────
    public function test_accept_transitions_assigned_to_accepted(): void
    {
        $rider = $this->rider();
        $delivery = $this->assignedDelivery($rider, Delivery::STATUS_ASSIGNED);

        $response = $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/accept");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.available_actions', ['pickup']);

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'status' => 'accepted',
        ]);
        $this->assertNotNull($delivery->fresh()->accepted_at);
    }

    public function test_accept_rejects_wrong_state(): void
    {
        $rider = $this->rider();
        $delivery = $this->assignedDelivery($rider, Delivery::STATUS_ACCEPTED);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('errors.current_status', 'accepted')
            ->assertJsonPath('errors.expected_status', 'assigned');
    }

    public function test_accept_returns_404_for_other_riders_delivery(): void
    {
        $rider = $this->rider();
        $other = $this->rider();
        $delivery = $this->assignedDelivery($other, Delivery::STATUS_ASSIGNED);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/accept")
            ->assertStatus(404);
    }

    // ─── pickup ───────────────────────────────────────────────
    public function test_pickup_transitions_accepted_to_picked_up(): void
    {
        $rider = $this->rider();
        $delivery = $this->assignedDelivery($rider, Delivery::STATUS_ACCEPTED);

        $response = $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/pickup");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'picked_up')
            ->assertJsonPath('data.available_actions', ['deliver']);

        $this->assertNotNull($delivery->fresh()->picked_up_at);
    }

    public function test_pickup_rejects_wrong_state(): void
    {
        $rider = $this->rider();
        $delivery = $this->assignedDelivery($rider, Delivery::STATUS_ASSIGNED);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/pickup")
            ->assertStatus(422);
    }

    // ─── deliver ──────────────────────────────────────────────
    public function test_deliver_transitions_picked_up_to_delivered(): void
    {
        $rider = $this->rider();
        $delivery = $this->assignedDelivery($rider, Delivery::STATUS_PICKED_UP);

        $response = $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/deliver");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.available_actions', []);

        $this->assertNotNull($delivery->fresh()->delivered_at);
    }

    public function test_deliver_rejects_wrong_state(): void
    {
        $rider = $this->rider();
        $delivery = $this->assignedDelivery($rider, Delivery::STATUS_ACCEPTED);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/deliver")
            ->assertStatus(422);
    }

    // ─── audit ────────────────────────────────────────────────
    public function test_full_flow_writes_audit_logs(): void
    {
        $rider = $this->rider();
        $delivery = $this->assignedDelivery($rider, Delivery::STATUS_ASSIGNED);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/accept")
            ->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delivery.accepted',
            'actor_id' => $rider->id,
        ]);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/pickup")
            ->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delivery.picked_up',
            'actor_id' => $rider->id,
        ]);

        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/deliver")
            ->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delivery.delivered',
            'actor_id' => $rider->id,
        ]);
    }
}