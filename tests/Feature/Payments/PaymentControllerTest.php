<?php

namespace Tests\Feature\Payments;

use App\Models\Delivery;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        config([
            'clickpesa.base_url'          => 'https://sandbox.clickpesa.com',
            'clickpesa.client_id'         => 'test-client',
            'clickpesa.client_secret'     => 'test-secret',
            'clickpesa.currency'          => 'TZS',
            'clickpesa.timeout'           => 5,
            'clickpesa.connect_timeout'   => 2,
            'clickpesa.token_ttl_minutes' => 55,
            'clickpesa.webhook_secret'    => 'whsec-test',
        ]);
    }

    protected function distributor(string $phone = '255712345678'): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create([
            'phone' => $phone,
        ]);
    }

    protected function pendingDelivery(User $distributor): Delivery
    {
        return Delivery::factory()->create([
            'distributor_id'   => $distributor->id,
            'service_type'     => Delivery::SERVICE_RECEIVE,
            'status'           => Delivery::STATUS_PENDING_PAYMENT,
            'total_amount'     => 5000,
            'ods_delivery_fee' => 5000,
            'currency'         => 'TZS',
        ]);
    }

    protected function fakeProviderSuccess(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/initiate-ussd-push-request' => Http::response([
                'id'     => 'CP-123',
                'status' => 'PENDING',
            ], 200),
        ]);
    }

    // ─── Auth ────────────────────────────────────────────────
    public function test_initiate_requires_authentication(): void
    {
        $this->postJson('/api/v1/distributor/payments/initiate', [])
            ->assertStatus(401);
    }

    public function test_initiate_rejects_non_distributor(): void
    {
        $rider = User::factory()->role(Role::RIDER)->create();
        $this->actingAs($rider, 'api')
            ->postJson('/api/v1/distributor/payments/initiate', [
                'delivery_id' => 1,
            ])
            ->assertStatus(403);
    }

    // ─── Happy path ──────────────────────────────────────────
    public function test_initiate_returns_201_and_payment_payload(): void
    {
        $this->fakeProviderSuccess();
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);

        $response = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/payments/initiate', [
                'delivery_id' => $delivery->id,
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stage', 'full')
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.provider_reference', 'CP-123')
            ->assertJsonPath('data.amount', 5000)
            ->assertJsonStructure([
                'data' => [
                    'id', 'reference', 'ussd' => ['provider_status', 'message'],
                ],
            ]);
    }

    public function test_initiate_uses_override_phone_number(): void
    {
        $this->fakeProviderSuccess();
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);

        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/payments/initiate', [
                'delivery_id'  => $delivery->id,
                'phone_number' => '255756111222',
            ])
            ->assertStatus(201);

        // Assert the USSD body carried the override (not the account phone)
        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'initiate-ussd-push-request')
                && $req['phoneNumber'] === '255756111222';
        });
    }

    // ─── Error paths ─────────────────────────────────────────
    public function test_initiate_returns_404_for_other_distributors_delivery(): void
    {
        $this->fakeProviderSuccess();
        $me = $this->distributor();
        $other = $this->distributor('255756111222');
        $otherDelivery = $this->pendingDelivery($other);

        $this->actingAs($me, 'api')
            ->postJson('/api/v1/distributor/payments/initiate', [
                'delivery_id' => $otherDelivery->id,
            ])
            ->assertStatus(404);
    }

    public function test_initiate_returns_422_for_paid_delivery(): void
    {
        $this->fakeProviderSuccess();
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);
        $delivery->update(['status' => Delivery::STATUS_PAID]);

        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/payments/initiate', [
                'delivery_id' => $delivery->id,
            ])
            ->assertStatus(422);
    }

    public function test_initiate_returns_409_for_duplicate_pending(): void
    {
        $this->fakeProviderSuccess();
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);

        // First initiation
        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/payments/initiate', [
                'delivery_id' => $delivery->id,
            ])
            ->assertStatus(201);

        // Second — 409
        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/payments/initiate', [
                'delivery_id' => $delivery->id,
            ])
            ->assertStatus(409);
    }

    // ─── Show + refresh ──────────────────────────────────────
    public function test_show_returns_payment_payload(): void
    {
        $this->fakeProviderSuccess();
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);

        $init = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/payments/initiate', [
                'delivery_id' => $delivery->id,
            ]);
        $paymentId = $init->json('data.id');

        $this->actingAs($distributor, 'api')
            ->getJson("/api/v1/distributor/payments/{$paymentId}")
            ->assertStatus(200)
            ->assertJsonPath('data.reference', Payment::find($paymentId)->reference);
    }

    public function test_refresh_queries_provider_and_updates_status(): void
    {
        $this->fakeProviderSuccess();
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);

        $init = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/payments/initiate', [
                'delivery_id' => $delivery->id,
            ]);
        $paymentId = $init->json('data.id');

        // Provider now says SUCCESS
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/PAY-*' => Http::response(['status' => 'SUCCESS'], 200),
        ]);

        $this->actingAs($distributor, 'api')
            ->postJson("/api/v1/distributor/payments/{$paymentId}/refresh")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.delivery_status', 'paid');
    }
}