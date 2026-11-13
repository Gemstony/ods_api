<?php

namespace Tests\Feature\Webhooks;

use App\Models\ClickPesaCallback;
use App\Models\Delivery;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClickPesaWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        config(['clickpesa.webhook_secret' => 'whsec-test']);
    }

    protected function distributor(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create([
            'phone' => '255712345678',
        ]);
    }

    protected function pendingPayment(User $distributor, string $reference = 'PAY-TESTWEBHOOK01'): Payment
    {
        $delivery = Delivery::factory()->create([
            'distributor_id'   => $distributor->id,
            'service_type'     => Delivery::SERVICE_RECEIVE,
            'status'           => Delivery::STATUS_PENDING_PAYMENT,
            'total_amount'     => 5000,
            'ods_delivery_fee' => 5000,
            'currency'         => 'TZS',
        ]);

        return Payment::create([
            'delivery_id'  => $delivery->id,
            'stage'        => Payment::STAGE_FULL,
            'amount'       => 5000,
            'currency'     => 'TZS',
            'method'       => Payment::METHOD_CLICKPESA,
            'status'       => Payment::STATUS_PROCESSING,
            'reference'    => $reference,
            'initiated_by' => $distributor->id,
            'initiated_at' => now(),
        ]);
    }

    protected function sign(array $payload): array
    {
        $raw = json_encode($payload);
        return [
            'raw' => $raw,
            'headers' => [
                'X-ClickPesa-Signature' => hash_hmac('sha256', $raw, 'whsec-test'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];
    }

    // ─── Auth is NOT required — public webhook ───────────────
    public function test_endpoint_is_public(): void
    {
        $payload = ['data' => ['orderReference' => 'PAY-X', 'status' => 'PENDING']];
        $sig = $this->sign($payload);

        $this->call('POST', '/api/v1/webhooks/clickpesa', [], [], [], [
            'HTTP_X_ClickPesa_Signature' => $sig['headers']['X-ClickPesa-Signature'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $sig['raw'])
            ->assertStatus(200);
    }

    // ─── Signature enforcement ───────────────────────────────
    public function test_rejects_invalid_signature(): void
    {
        $payload = ['data' => ['orderReference' => 'PAY-X', 'status' => 'SUCCESS']];
        $raw = json_encode($payload);

        $this->call('POST', '/api/v1/webhooks/clickpesa', [], [], [], [
            'HTTP_X_ClickPesa_Signature' => 'bogus',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $raw)
            ->assertStatus(401);
    }

    public function test_rejects_missing_signature_when_secret_configured(): void
    {
        $payload = ['data' => ['orderReference' => 'PAY-X', 'status' => 'SUCCESS']];

        $this->call('POST', '/api/v1/webhooks/clickpesa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($payload))
            ->assertStatus(401);
    }

    public function test_accepts_unsigned_when_secret_empty(): void
    {
        config(['clickpesa.webhook_secret' => '']);
        $payload = ['data' => ['orderReference' => 'PAY-X', 'status' => 'PENDING']];

        $this->call('POST', '/api/v1/webhooks/clickpesa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($payload))
            ->assertStatus(200);

        $callback = ClickPesaCallback::first();
        $this->assertFalse($callback->signature_valid);
    }

    // ─── Happy path ──────────────────────────────────────────
    public function test_success_event_marks_payment_completed_and_delivery_paid(): void
    {
        $distributor = $this->distributor();
        $payment = $this->pendingPayment($distributor);

        $payload = [
            'data' => [
                'id'             => 'CP-123',
                'orderReference' => $payment->reference,
                'status'         => 'SUCCESS',
                'amount'         => '5000',
            ],
        ];
        $sig = $this->sign($payload);

        $this->call('POST', '/api/v1/webhooks/clickpesa', [], [], [], [
            'HTTP_X_ClickPesa_Signature' => $sig['headers']['X-ClickPesa-Signature'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $sig['raw'])
            ->assertStatus(200);

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertSame(Delivery::STATUS_PAID, $payment->delivery->fresh()->status);

        $callback = ClickPesaCallback::first();
        $this->assertNotNull($callback->processed_at);
        $this->assertSame($payment->id, $callback->payment_id);
        $this->assertNull($callback->processing_error);
    }

    public function test_failed_event_marks_payment_failed(): void
    {
        $distributor = $this->distributor();
        $payment = $this->pendingPayment($distributor);

        $payload = [
            'data' => [
                'orderReference' => $payment->reference,
                'status'         => 'FAILED',
                'message'        => 'Customer cancelled USSD prompt',
            ],
        ];
        $sig = $this->sign($payload);

        $this->call('POST', '/api/v1/webhooks/clickpesa', [], [], [], [
            'HTTP_X_ClickPesa_Signature' => $sig['headers']['X-ClickPesa-Signature'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $sig['raw'])
            ->assertStatus(200);

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertStringContainsString('cancelled', (string) $payment->fresh()->failure_reason);
    }

    // ─── Logging / idempotency ───────────────────────────────
    public function test_callback_logged_even_when_payment_not_found(): void
    {
        $payload = [
            'data' => [
                'orderReference' => 'PAY-NONEXISTENT',
                'status'         => 'SUCCESS',
            ],
        ];
        $sig = $this->sign($payload);

        $this->call('POST', '/api/v1/webhooks/clickpesa', [], [], [], [
            'HTTP_X_ClickPesa_Signature' => $sig['headers']['X-ClickPesa-Signature'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $sig['raw'])
            ->assertStatus(200);

        $callback = ClickPesaCallback::first();
        $this->assertNotNull($callback);
        $this->assertStringContainsString('No payment matches', (string) $callback->processing_error);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $distributor = $this->distributor();
        $payment = $this->pendingPayment($distributor);

        $payload = [
            'data' => [
                'orderReference' => $payment->reference,
                'status'         => 'SUCCESS',
            ],
        ];
        $sig = $this->sign($payload);

        // First delivery of the webhook
        $this->call('POST', '/api/v1/webhooks/clickpesa', [], [], [], [
            'HTTP_X_ClickPesa_Signature' => $sig['headers']['X-ClickPesa-Signature'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $sig['raw'])->assertStatus(200);
        $firstPaidAt = $payment->fresh()->paid_at;

        // Duplicate delivery
        $this->call('POST', '/api/v1/webhooks/clickpesa', [], [], [], [
            'HTTP_X_ClickPesa_Signature' => $sig['headers']['X-ClickPesa-Signature'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $sig['raw'])->assertStatus(200);

        // State unchanged — paid_at did not regress
        $this->assertSame($firstPaidAt->toIso8601String(), $payment->fresh()->paid_at->toIso8601String());
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
    }
}