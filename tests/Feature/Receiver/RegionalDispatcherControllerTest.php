<?php

namespace Tests\Feature\Receiver;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\DispatcherTicket;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionalDispatcherControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
    }

    protected function branch(): Branch
    {
        return Branch::factory()->create();
    }

    protected function dispatcherReceiver(Branch $branch): User
    {
        return User::factory()->role(Role::RECEIVER)->create([
            'branch_id' => $branch->id,
            'dispatcher_enabled' => true,
        ]);
    }

    protected function makeRegional(
        Branch $branch,
        string $status = Delivery::STATUS_BATCHED,
        float $busFare = 3500,
    ): Delivery {
        return Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_REGIONAL,
            'status' => $status,
            'bus_cargo_fare' => $busFare,
            'destination_region' => 'Arusha',
            'destination_district' => 'Arusha City',
            'destination_bus_stop' => 'Nairobi Road Station',
        ]);
    }

    // ─── Auth + gate ──────────────────────────────────────────
    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/receiver/regional')->assertStatus(401);
    }

    public function test_index_rejects_non_receiver(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/receiver/regional')
            ->assertStatus(403);
    }

    public function test_index_rejects_receiver_without_dispatcher_flag(): void
    {
        $branch = $this->branch();
        $plainReceiver = User::factory()->role(Role::RECEIVER)->create([
            'branch_id' => $branch->id,
            'dispatcher_enabled' => false,
        ]);

        $this->actingAs($plainReceiver, 'api')
            ->getJson('/api/v1/receiver/regional')
            ->assertStatus(403);
    }

    // ─── index ────────────────────────────────────────────────
    public function test_index_returns_only_regional_deliveries(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);

        $this->makeRegional($branch);
        Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PAID,
        ]);

        $response = $this->actingAs($receiver, 'api')
            ->getJson('/api/v1/receiver/regional');

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('regional', $response->json('data.data.0.service_type'));
    }

    public function test_index_filters_by_status(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);

        $this->makeRegional($branch, Delivery::STATUS_BATCHED);
        $this->makeRegional($branch, Delivery::STATUS_ASSIGNED);

        $response = $this->actingAs($receiver, 'api')
            ->getJson('/api/v1/receiver/regional?status=batched');

        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('batched', $response->json('data.data.0.status'));
    }

    // ─── assign ───────────────────────────────────────────────
    public function test_assign_transitions_batched_to_assigned(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional($branch, Delivery::STATUS_BATCHED);

        $response = $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/assign");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.dispatcher_id', $receiver->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delivery.dispatcher_assigned',
            'actor_id' => $receiver->id,
        ]);
    }

    public function test_assign_422s_on_wrong_status(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional($branch, Delivery::STATUS_ASSIGNED);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/assign")
            ->assertStatus(422)
            ->assertJsonPath('errors.current_status', 'assigned')
            ->assertJsonPath('errors.expected_status', 'batched');
    }

    public function test_assign_404s_for_other_branch(): void
    {
        $branch = $this->branch();
        $otherBranch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional($otherBranch);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/assign")
            ->assertStatus(404);
    }

    // ─── dispatch ─────────────────────────────────────────────
    public function test_dispatch_transitions_assigned_to_dispatched(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional($branch, Delivery::STATUS_ASSIGNED);
        $delivery->update(['dispatcher_id' => $receiver->id]);

        $response = $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/dispatch", [
                'provider_name' => 'Kilimanjaro Express',
                'provider_type' => 'bus',
                'ticket_number' => 'TKT-1234',
                'route_from' => 'Dar es Salaam',
                'route_to' => 'Arusha',
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dispatched')
            ->assertJsonPath('data.dispatcher_ticket.provider_name', 'Kilimanjaro Express')
            ->assertJsonPath('data.dispatcher_ticket.ticket_number', 'TKT-1234');

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'status' => 'dispatched',
        ]);
        $this->assertNotNull($delivery->fresh()->dispatched_at);
        $this->assertDatabaseHas('dispatcher_tickets', [
            'delivery_id' => $delivery->id,
            'dispatcher_id' => $receiver->id,
            'ticket_number' => 'TKT-1234',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delivery.dispatched',
            'actor_id' => $receiver->id,
        ]);
    }

    public function test_dispatch_inherits_transport_fare_from_bus_cargo_fare(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional(
            $branch,
            Delivery::STATUS_ASSIGNED,
            busFare: 4500,
        );
        $delivery->update(['dispatcher_id' => $receiver->id]);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/dispatch", [
                'provider_name' => 'Express Bus',
                'provider_type' => 'bus',
                'ticket_number' => 'TKT-1',
                'route_from' => 'DSM',
                'route_to' => 'Arusha',
            ])
            ->assertStatus(200);

        $ticket = DispatcherTicket::where('delivery_id', $delivery->id)->first();
        $this->assertSame(4500.0, (float) $ticket->transport_fare);
    }

    public function test_dispatch_allows_explicit_transport_fare_override(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional(
            $branch,
            Delivery::STATUS_ASSIGNED,
            busFare: 3500,
        );
        $delivery->update(['dispatcher_id' => $receiver->id]);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/dispatch", [
                'provider_name' => 'Express Bus',
                'provider_type' => 'bus',
                'ticket_number' => 'TKT-1',
                'route_from' => 'DSM',
                'route_to' => 'Arusha',
                'transport_fare' => 5000,
            ])
            ->assertStatus(200);

        $ticket = DispatcherTicket::where('delivery_id', $delivery->id)->first();
        $this->assertSame(5000.0, (float) $ticket->transport_fare);
    }

    public function test_dispatch_422s_on_wrong_status(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional($branch, Delivery::STATUS_BATCHED);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/dispatch", [
                'provider_name' => 'X',
                'provider_type' => 'bus',
                'ticket_number' => 'TKT-1',
                'route_from' => 'A',
                'route_to' => 'B',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_status', 'batched')
            ->assertJsonPath('errors.expected_status', 'assigned');
    }

    public function test_dispatch_validates_provider_fields(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional($branch, Delivery::STATUS_ASSIGNED);
        $delivery->update(['dispatcher_id' => $receiver->id]);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/dispatch", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => [
                'provider_name',
                'provider_type',
                'ticket_number',
                'route_from',
                'route_to',
            ]]);
    }

    // ─── confirmArrival (9.5) ─────────────────────────────────
    public function test_confirm_arrival_transitions_dispatched_to_delivered(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional($branch, Delivery::STATUS_DISPATCHED);
        $delivery->update([
            'dispatcher_id' => $receiver->id,
            'dispatched_at' => now()->subHours(6),
        ]);

        $response = $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/confirm-arrival");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'delivered');

        $fresh = $delivery->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delivery.arrived_at_destination',
            'actor_id' => $receiver->id,
        ]);
    }

    public function test_confirm_arrival_422s_on_wrong_status(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional($branch, Delivery::STATUS_ASSIGNED);
        $delivery->update(['dispatcher_id' => $receiver->id]);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/confirm-arrival")
            ->assertStatus(422)
            ->assertJsonPath('errors.current_status', 'assigned')
            ->assertJsonPath('errors.expected_status', 'dispatched');
    }

    public function test_confirm_arrival_404s_for_other_branch(): void
    {
        $branch = $this->branch();
        $otherBranch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional($otherBranch, Delivery::STATUS_DISPATCHED);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/confirm-arrival")
            ->assertStatus(404);
    }

    public function test_confirm_arrival_requires_dispatcher_flag(): void
    {
        $branch = $this->branch();
        $plainReceiver = User::factory()->role(Role::RECEIVER)->create([
            'branch_id' => $branch->id,
            'dispatcher_enabled' => false,
        ]);
        $delivery = $this->makeRegional($branch, Delivery::STATUS_DISPATCHED);

        $this->actingAs($plainReceiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/confirm-arrival")
            ->assertStatus(403);
    }

    public function test_confirm_arrival_records_delivered_at_timestamp(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional($branch, Delivery::STATUS_DISPATCHED);
        $delivery->update([
            'dispatcher_id' => $receiver->id,
            'dispatched_at' => now()->subDays(2),
        ]);

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/regional/{$delivery->id}/confirm-arrival")
            ->assertStatus(200);

        $fresh = $delivery->fresh();
        $this->assertNotNull($fresh->delivered_at);
        // delivered_at should be essentially "now", not the dispatched_at
        $this->assertTrue(
            $fresh->delivered_at->isAfter($fresh->dispatched_at),
            'delivered_at should be later than dispatched_at',
        );
    }

    // ─── ticket ───────────────────────────────────────────────
    public function test_ticket_returns_existing_ticket(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional($branch, Delivery::STATUS_DISPATCHED);
        DispatcherTicket::factory()->create([
            'delivery_id' => $delivery->id,
            'dispatcher_id' => $receiver->id,
            'ticket_number' => 'TKT-EXIST',
        ]);

        $this->actingAs($receiver, 'api')
            ->getJson("/api/v1/receiver/regional/{$delivery->id}/ticket")
            ->assertStatus(200)
            ->assertJsonPath('data.ticket_number', 'TKT-EXIST');
    }

    public function test_ticket_404s_when_none_exists(): void
    {
        $branch = $this->branch();
        $receiver = $this->dispatcherReceiver($branch);
        $delivery = $this->makeRegional($branch, Delivery::STATUS_ASSIGNED);

        $this->actingAs($receiver, 'api')
            ->getJson("/api/v1/receiver/regional/{$delivery->id}/ticket")
            ->assertStatus(404);
    }
}