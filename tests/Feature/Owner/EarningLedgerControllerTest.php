<?php

namespace Tests\Feature\Owner;

use App\Models\Delivery;
use App\Models\Earning;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EarningLedgerControllerTest extends TestCase
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

    protected function earning(array $overrides = []): Earning
    {
        $rider = User::factory()->role(Role::RIDER)->create();
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'rider_id' => $rider->id,
            'status' => Delivery::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);

        return Earning::create(array_merge([
            'user_id' => $rider->id,
            'delivery_id' => $delivery->id,
            'type' => Earning::TYPE_RIDER_BASELINE,
            'amount' => 4000,
            'currency' => 'TZS',
            'status' => Earning::STATUS_PENDING,
            'earned_at' => now(),
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/owner/earnings')->assertStatus(401);
    }

    public function test_index_rejects_non_owner(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/owner/earnings')
            ->assertStatus(403);
    }

    public function test_index_returns_paginated_list(): void
    {
        $this->earning();
        $this->earning();

        $response = $this->actingAs($this->owner(), 'api')
            ->getJson('/api/v1/owner/earnings');

        $response->assertStatus(200)->assertJsonPath('data.total', 2);
    }

    public function test_index_filters_by_status(): void
    {
        $this->earning(['status' => Earning::STATUS_PENDING]);
        $this->earning(['status' => Earning::STATUS_APPROVED]);

        $response = $this->actingAs($this->owner(), 'api')
            ->getJson('/api/v1/owner/earnings?status=approved');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('approved', $response->json('data.data.0.status'));
    }

    public function test_index_filters_by_type(): void
    {
        $this->earning(['type' => Earning::TYPE_RIDER_BASELINE]);
        $this->earning(['type' => Earning::TYPE_RIDER_BONUS, 'amount' => 500]);

        $response = $this->actingAs($this->owner(), 'api')
            ->getJson('/api/v1/owner/earnings?type=rider_bonus');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('rider_bonus', $response->json('data.data.0.type'));
    }

    public function test_show_returns_detail(): void
    {
        $earning = $this->earning();

        $response = $this->actingAs($this->owner(), 'api')
            ->getJson("/api/v1/owner/earnings/{$earning->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.id', $earning->id)
            ->assertJsonPath('data.amount', 4000)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure([
                'data' => [
                    'id', 'user_id', 'delivery_id', 'type', 'amount', 'status',
                    'user' => ['id', 'name', 'email'],
                    'delivery' => ['id', 'tracking_id'],
                ],
            ]);
    }

    public function test_show_returns_404_for_nonexistent(): void
    {
        $this->actingAs($this->owner(), 'api')
            ->getJson('/api/v1/owner/earnings/9999')
            ->assertStatus(404);
    }
}