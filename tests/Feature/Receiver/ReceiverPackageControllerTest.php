<?php

namespace Tests\Feature\Receiver;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiverPackageControllerTest extends TestCase
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

    protected function receiver(?Branch $branch = null, ?bool $dispatcher = null): User
    {
        return User::factory()->role(Role::RECEIVER)->create([
            'branch_id' => $branch?->id,
            'dispatcher_enabled' => $dispatcher ?? false,
        ]);
    }

    protected function rider(): User
    {
        return User::factory()->role(Role::RIDER)->create();
    }

    protected function makeReceiveDelivery(
        Branch $branch,
        string $status = Delivery::STATUS_ACCEPTED,
        ?User $rider = null,
    ): Delivery {
        return Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => $status,
            'rider_id' => $rider?->id,
        ]);
    }

    // ─── Auth ─────────────────────────────────────────────────
    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/receiver/packages')->assertStatus(401);
    }

    public function test_index_rejects_other_roles(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/receiver/packages')
            ->assertStatus(403);
    }

    public function test_index_rejects_receiver_without_branch(): void
    {
        $receiver = $this->receiver();
        $this->actingAs($receiver, 'api')
            ->getJson('/api/v1/receiver/packages')
            ->assertStatus(403);
    }

    // ─── index ────────────────────────────────────────────────
    public function test_index_returns_only_branchs_receive_deliveries(): void
    {
        $branch = $this->branch();
        $otherBranch = $this->branch();
        $receiver = $this->receiver($branch);

        $this->makeReceiveDelivery($branch);
        $this->makeReceiveDelivery($otherBranch);

        $response = $this->actingAs($receiver, 'api')
            ->getJson('/api/v1/receiver/packages');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_index_excludes_send_and_regional_deliveries(): void
    {
        $branch = $this->branch();
        $receiver = $this->receiver($branch);

        $this->makeReceiveDelivery($branch);
        Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_SEND,
            'status' => Delivery::STATUS_ACCEPTED,
        ]);
        Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_REGIONAL,
            'status' => Delivery::STATUS_BATCHED,
        ]);

        $response = $this->actingAs($receiver, 'api')
            ->getJson('/api/v1/receiver/packages');

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('receive', $response->json('data.data.0.service_type'));
    }

    public function test_index_filters_by_status(): void
    {
        $branch = $this->branch();
        $receiver = $this->receiver($branch);

        $this->makeReceiveDelivery($branch, Delivery::STATUS_PAID);
        $this->makeReceiveDelivery($branch, Delivery::STATUS_ACCEPTED);

        $response = $this->actingAs($receiver, 'api')
            ->getJson('/api/v1/receiver/packages?status=accepted');

        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('accepted', $response->json('data.data.0.status'));
    }

    // ─── show ─────────────────────────────────────────────────
    public function test_show_404s_for_other_branch(): void
    {
        $branch = $this->branch();
        $otherBranch = $this->branch();
        $receiver = $this->receiver($branch);
        $delivery = $this->makeReceiveDelivery($otherBranch);

        $this->actingAs($receiver, 'api')
            ->getJson("/api/v1/receiver/packages/{$delivery->id}")
            ->assertStatus(404);
    }

    public function test_show_404s_for_send_service_type(): void
    {
        $branch = $this->branch();
        $receiver = $this->receiver($branch);
        $send = Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_SEND,
            'status' => Delivery::STATUS_ACCEPTED,
        ]);

        $this->actingAs($receiver, 'api')
            ->getJson("/api/v1/receiver/packages/{$send->id}")
            ->assertStatus(404);
    }

    // ─── verify ───────────────────────────────────────────────
    public function test_verify_returns_can_release_true_when_ready(): void
    {
        $branch = $this->branch();
        $receiver = $this->receiver($branch);
        $rider = $this->rider();
        $delivery = $this->makeReceiveDelivery(
            $branch,
            Delivery::STATUS_ACCEPTED,
            $rider,
        );

        $response = $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/packages/{$delivery->id}/verify");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.can_release', true)
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.rider.id', $rider->id);
    }

    public function test_verify_returns_false_when_wrong_status(): void
    {
        $branch = $this->branch();
        $receiver = $this->receiver($branch);
        $rider = $this->rider();
        $delivery = $this->makeReceiveDelivery(
            $branch,
            Delivery::STATUS_ASSIGNED,
            $rider,
        );

        $response = $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/packages/{$delivery->id}/verify");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.can_release', false);
        $this->assertStringContainsString(
            'assigned',
            (string) $response->json('data.reason'),
        );
    }

    public function test_verify_returns_false_when_no_rider(): void
    {
        $branch = $this->branch();
        $receiver = $this->receiver($branch);
        $delivery = $this->makeReceiveDelivery(
            $branch,
            Delivery::STATUS_ACCEPTED,
            null,
        );

        $response = $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/packages/{$delivery->id}/verify");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.can_release', false);
        $this->assertStringContainsString(
            'No rider',
            (string) $response->json('data.reason'),
        );
    }

    // ─── release ──────────────────────────────────────────────
    public function test_release_transitions_to_picked_up_and_audits(): void
    {
        $branch = $this->branch();
        $receiver = $this->receiver($branch);
        $rider = $this->rider();
        $delivery = $this->makeReceiveDelivery(
            $branch,
            Delivery::STATUS_ACCEPTED,
            $rider,
        );

        $response = $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/packages/{$delivery->id}/release");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'picked_up');

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'status' => 'picked_up',
        ]);
        $this->assertNotNull($delivery->fresh()->picked_up_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delivery.released_by_receiver',
            'actor_id' => $receiver->id,
        ]);
    }

    public function test_release_422s_on_wrong_status(): void
    {
        $branch = $this->branch();
        $receiver = $this->receiver($branch);
        $rider = $this->rider();
        $delivery = $this->makeReceiveDelivery(
            $branch,
            Delivery::STATUS_ASSIGNED,
            $rider,
        );

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/packages/{$delivery->id}/release")
            ->assertStatus(422)
            ->assertJsonPath('errors.current_status', 'assigned');
    }

    public function test_release_404s_for_other_branch(): void
    {
        $branch = $this->branch();
        $otherBranch = $this->branch();
        $receiver = $this->receiver($branch);
        $rider = $this->rider();
        $delivery = $this->makeReceiveDelivery(
            $otherBranch,
            Delivery::STATUS_ACCEPTED,
            $rider,
        );

        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/packages/{$delivery->id}/release")
            ->assertStatus(404);
    }

    // ─── Option A — cross-actor conflict ──────────────────────
    public function test_rider_pickup_after_receiver_release_returns_422(): void
    {
        $branch = $this->branch();
        $receiver = $this->receiver($branch);
        $rider = $this->rider();
        $delivery = $this->makeReceiveDelivery(
            $branch,
            Delivery::STATUS_ACCEPTED,
            $rider,
        );

        // Receiver releases first
        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/packages/{$delivery->id}/release")
            ->assertStatus(200);

        // Rider tries to pick up second — sees picked_up, not accepted
        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/pickup")
            ->assertStatus(422)
            ->assertJsonPath('errors.current_status', 'picked_up')
            ->assertJsonPath('errors.expected_status', 'accepted');
    }

    public function test_receiver_release_after_rider_pickup_returns_422(): void
    {
        $branch = $this->branch();
        $receiver = $this->receiver($branch);
        $rider = $this->rider();
        $delivery = $this->makeReceiveDelivery(
            $branch,
            Delivery::STATUS_ACCEPTED,
            $rider,
        );

        // Rider picks up first
        $this->actingAs($rider, 'api')
            ->postJson("/api/v1/rider/deliveries/{$delivery->id}/pickup")
            ->assertStatus(200);

        // Receiver tries to release second
        $this->actingAs($receiver, 'api')
            ->postJson("/api/v1/receiver/packages/{$delivery->id}/release")
            ->assertStatus(422)
            ->assertJsonPath('errors.current_status', 'picked_up');
    }
}