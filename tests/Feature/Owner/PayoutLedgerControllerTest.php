<?php

namespace Tests\Feature\Owner;

use App\Models\Earning;
use App\Models\Payout;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutLedgerControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
    }

    protected function owner(): User
    {
        return User::factory()->role(Role::OWNER)->create();
    }

    protected function payout(array $overrides = []): Payout
    {
        $rider = User::factory()->role(Role::RIDER)->create();

        return Payout::create(array_merge([
            'user_id' => $rider->id,
            'amount' => 40000,
            'currency' => 'TZS',
            'method' => Payout::METHOD_MOBILE,
            'status' => Payout::STATUS_PENDING,
            'requested_at' => now(),
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/owner/payouts')->assertStatus(401);
    }

    public function test_index_rejects_non_owner(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/owner/payouts')
            ->assertStatus(403);
    }

    public function test_index_returns_paginated_list(): void
    {
        $this->payout();
        $this->payout();

        $response = $this->actingAs($this->owner(), 'api')
            ->getJson('/api/v1/owner/payouts');

        $response->assertStatus(200)->assertJsonPath('data.total', 2);
    }

    public function test_index_filters_by_status(): void
    {
        $this->payout(['status' => Payout::STATUS_PENDING]);
        $this->payout(['status' => Payout::STATUS_PAID, 'processed_at' => now()]);

        $response = $this->actingAs($this->owner(), 'api')
            ->getJson('/api/v1/owner/payouts?status=paid');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_show_returns_detail_with_earnings(): void
    {
        $payout = $this->payout();
        $rider = User::find($payout->user_id);

        // Attach one earning to this payout
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $delivery = \App\Models\Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'rider_id' => $rider->id,
            'status' => \App\Models\Delivery::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
        Earning::create([
            'user_id' => $rider->id,
            'delivery_id' => $delivery->id,
            'type' => Earning::TYPE_RIDER_BASELINE,
            'amount' => 4000,
            'currency' => 'TZS',
            'status' => Earning::STATUS_PAID,
            'earned_at' => now(),
            'paid_at' => now(),
            'payout_id' => $payout->id,
        ]);

        $response = $this->actingAs($this->owner(), 'api')
            ->getJson("/api/v1/owner/payouts/{$payout->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.id', $payout->id)
            ->assertJsonStructure([
                'data' => [
                    'id', 'user_id', 'amount', 'status',
                    'user' => ['id', 'name'],
                    'earnings' => [['id', 'type', 'amount', 'status']],
                ],
            ]);
        $this->assertCount(1, $response->json('data.earnings'));
    }

    public function test_show_returns_404_for_nonexistent(): void
    {
        $this->actingAs($this->owner(), 'api')
            ->getJson('/api/v1/owner/payouts/9999')
            ->assertStatus(404);
    }
}