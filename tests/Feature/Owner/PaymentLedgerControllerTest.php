<?php

namespace Tests\Feature\Owner;

use App\Models\Delivery;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentLedgerControllerTest extends TestCase
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

    protected function payment(array $overrides = []): Payment
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'status' => Delivery::STATUS_PENDING_PAYMENT,
        ]);

        return Payment::create(array_merge([
            'delivery_id' => $delivery->id,
            'stage' => Payment::STAGE_FULL,
            'amount' => 5000,
            'currency' => 'TZS',
            'method' => Payment::METHOD_CLICKPESA,
            'status' => Payment::STATUS_PENDING,
            'initiated_by' => $distributor->id,
            'initiated_at' => now(),
        ], $overrides));
    }

    // ─── Auth ────────────────────────────────────────────────
    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/owner/payments')->assertStatus(401);
    }

    public function test_index_rejects_non_owner(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/owner/payments')
            ->assertStatus(403);
    }

    // ─── Index ───────────────────────────────────────────────
    public function test_index_returns_paginated_list(): void
    {
        $this->payment();
        $this->payment();
        $this->payment();

        $response = $this->actingAs($this->owner(), 'api')
            ->getJson('/api/v1/owner/payments');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.total', 3);
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_index_filters_by_status(): void
    {
        $this->payment(['status' => Payment::STATUS_PENDING]);
        $this->payment(['status' => Payment::STATUS_COMPLETED, 'paid_at' => now()]);
        $this->payment(['status' => Payment::STATUS_FAILED]);

        $response = $this->actingAs($this->owner(), 'api')
            ->getJson('/api/v1/owner/payments?status=completed');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('completed', $response->json('data.data.0.status'));
    }

    public function test_index_filters_by_delivery_id(): void
    {
        $p1 = $this->payment();
        $p2 = $this->payment();

        $response = $this->actingAs($this->owner(), 'api')
            ->getJson('/api/v1/owner/payments?delivery_id=' . $p1->delivery_id);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($p1->delivery_id, $response->json('data.data.0.delivery_id'));
    }

    public function test_index_filters_by_reference(): void
    {
        $target = $this->payment();
        $this->payment();

        $response = $this->actingAs($this->owner(), 'api')
            ->getJson('/api/v1/owner/payments?reference=' . $target->reference);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($target->reference, $response->json('data.data.0.reference'));
    }

    // ─── Show ────────────────────────────────────────────────
    public function test_show_returns_detail_with_transactions(): void
    {
        $payment = $this->payment(['status' => Payment::STATUS_PROCESSING]);
        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'type' => 'initiate',
            'provider' => 'clickpesa',
            'status' => 'success',
            'attempted_at' => now(),
        ]);

        $response = $this->actingAs($this->owner(), 'api')
            ->getJson("/api/v1/owner/payments/{$payment->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.id', $payment->id)
            ->assertJsonPath('data.reference', $payment->reference)
            ->assertJsonStructure([
                'data' => [
                    'id', 'reference', 'amount', 'status',
                    'delivery' => ['id', 'tracking_id'],
                    'initiated_by' => ['id', 'name'],
                    'transactions' => [['id', 'type', 'status']],
                ],
            ]);
        $this->assertCount(1, $response->json('data.transactions'));
    }

    public function test_show_returns_404_for_nonexistent(): void
    {
        $this->actingAs($this->owner(), 'api')
            ->getJson('/api/v1/owner/payments/9999')
            ->assertStatus(404);
    }
}