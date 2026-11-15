<?php

namespace Tests\Feature\Disputes;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Dispute;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisputeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        (new SettingsSeeder())->run();
    }

    protected function owner(): User
    {
        return User::factory()->role(Role::OWNER)->create();
    }

    protected function distributor(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create();
    }

    protected function delivery(?User $distributor = null, ?User $rider = null, ?Branch $branch = null): Delivery
    {
        return Delivery::factory()->create([
            'distributor_id' => ($distributor ?? $this->distributor())->id,
            'rider_id' => $rider?->id,
            'pickup_branch_id' => ($branch ?? Branch::factory()->create())->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_DELIVERED,
        ]);
    }

    protected function disputeFor(Delivery $delivery, User $opener): Dispute
    {
        return Dispute::create([
            'delivery_id' => $delivery->id,
            'opened_by' => $opener->id,
            'category' => Dispute::CATEGORY_DELIVERY,
            'subject' => 'Test subject',
            'description' => 'Test description that is long enough.',
            'priority' => Dispute::PRIORITY_NORMAL,
            'status' => Dispute::STATUS_OPEN,
        ]);
    }

    // ─── Distributor ────────────────────────────────────────
    public function test_distributor_store_creates_dispute(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->delivery(distributor: $distributor);

        $response = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/disputes', [
                'delivery_id' => $delivery->id,
                'category' => Dispute::CATEGORY_DELIVERY,
                'subject' => 'Package damaged',
                'description' => 'The package arrived visibly crushed.',
                'priority' => Dispute::PRIORITY_HIGH,
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.subject', 'Package damaged')
            ->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('disputes', [
            'opened_by' => $distributor->id,
            'delivery_id' => $delivery->id,
        ]);
    }

    public function test_distributor_index_returns_only_own_disputes(): void
    {
        $me = $this->distributor();
        $other = $this->distributor();
        $this->disputeFor($this->delivery(distributor: $me), $me);
        $this->disputeFor($this->delivery(distributor: $other), $other);

        $response = $this->actingAs($me, 'api')
            ->getJson('/api/v1/distributor/disputes');

        $response->assertStatus(200)->assertJsonPath('data.total', 1);
    }

    public function test_distributor_show_404s_for_other_users_dispute(): void
    {
        $me = $this->distributor();
        $other = $this->distributor();
        $dispute = $this->disputeFor($this->delivery(distributor: $other), $other);

        $this->actingAs($me, 'api')
            ->getJson("/api/v1/distributor/disputes/{$dispute->id}")
            ->assertStatus(404);
    }

    // ─── Manager ────────────────────────────────────────────
    public function test_manager_store_creates_dispute_about_branch_delivery(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->role(Role::MANAGER)->create([
            'branch_id' => $branch->id,
        ]);
        $delivery = $this->delivery(branch: $branch);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/disputes', [
                'delivery_id' => $delivery->id,
                'category' => Dispute::CATEGORY_PACKAGE,
                'subject' => 'Missing item',
                'description' => 'The package is missing one of the requested items.',
            ])
            ->assertStatus(201);
    }

    public function test_manager_cannot_store_dispute_for_other_branch(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $manager = User::factory()->role(Role::MANAGER)->create([
            'branch_id' => $branch->id,
        ]);
        $delivery = $this->delivery(branch: $otherBranch);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/disputes', [
                'delivery_id' => $delivery->id,
                'category' => Dispute::CATEGORY_PACKAGE,
                'subject' => 'Test',
                'description' => 'Test description that is long enough.',
            ])
            ->assertStatus(403);
    }

    // ─── Owner ──────────────────────────────────────────────
    public function test_owner_index_returns_all_disputes(): void
    {
        $owner = $this->owner();
        $d1 = $this->disputeFor($this->delivery(), $this->distributor());
        $d2 = $this->disputeFor($this->delivery(), $this->distributor());

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/disputes');

        $response->assertStatus(200)->assertJsonPath('data.total', 2);
    }

    public function test_owner_can_resolve_dispute(): void
    {
        $owner = $this->owner();
        $dispute = $this->disputeFor($this->delivery(), $this->distributor());

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/disputes/{$dispute->id}/resolve", [
                'resolution' => 'Refunded 5000 TZS as compensation.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'resolved');

        $this->assertSame(
            Dispute::STATUS_RESOLVED,
            $dispute->fresh()->status,
        );
    }

    public function test_owner_resolve_requires_resolution_body(): void
    {
        $owner = $this->owner();
        $dispute = $this->disputeFor($this->delivery(), $this->distributor());

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/disputes/{$dispute->id}/resolve", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['resolution']]);
    }

    public function test_owner_can_close_dispute(): void
    {
        $owner = $this->owner();
        $dispute = $this->disputeFor($this->delivery(), $this->distributor());

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/disputes/{$dispute->id}/close", [
                'reason' => 'Duplicate of a prior report.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_owner_can_escalate_dispute(): void
    {
        $owner = $this->owner();
        $dispute = $this->disputeFor($this->delivery(), $this->distributor());

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/disputes/{$dispute->id}/escalate")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'escalated');
    }

    public function test_owner_can_start_review(): void
    {
        $owner = $this->owner();
        $dispute = $this->disputeFor($this->delivery(), $this->distributor());

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/disputes/{$dispute->id}/review")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'in_review');
    }

    public function test_owner_resolve_409s_on_terminal_state(): void
    {
        $owner = $this->owner();
        $dispute = $this->disputeFor($this->delivery(), $this->distributor());
        $dispute->update(['status' => Dispute::STATUS_RESOLVED]);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/disputes/{$dispute->id}/resolve", [
                'resolution' => 'Try again.',
            ])
            ->assertStatus(409);
    }

    // ─── Access gates ───────────────────────────────────────
    public function test_distributor_cannot_access_owner_disputes(): void
    {
        $distributor = $this->distributor();

        $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/owner/disputes')
            ->assertStatus(403);
    }

    public function test_owner_cannot_access_distributor_disputes(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/distributor/disputes', [
                'delivery_id' => 1,
                'category' => 'delivery',
                'subject' => 'Test',
                'description' => 'Test description that is long enough.',
            ])
            ->assertStatus(403);
    }
}