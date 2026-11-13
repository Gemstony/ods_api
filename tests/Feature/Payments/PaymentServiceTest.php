<?php

namespace Tests\Feature\Payments;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
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

    protected function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    protected function distributor(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create([
            'phone' => '255712345678',
        ]);
    }

    protected function pendingDelivery(User $distributor, array $overrides = []): Delivery
    {
        return Delivery::factory()->create(array_merge([
            'distributor_id'     => $distributor->id,
            'service_type'       => Delivery::SERVICE_RECEIVE,
            'status'             => Delivery::STATUS_PENDING_PAYMENT,
            'total_amount'       => 5000,
            'ods_delivery_fee'   => 5000,
            'discount_amount'    => 0,
            'bus_cargo_fare'     => null,
            'currency'           => 'TZS',
        ], $overrides));
    }

    protected function fakeInitiateSuccess(array $response = []): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/initiate-ussd-push-request' => Http::response(array_merge([
                'id'             => 'CP-123',
                'orderReference' => 'PAY-X',
                'status'         => 'PENDING',
            ], $response), 200),
        ]);
    }

    // ─── Initiate: happy path ────────────────────────────────
    public function test_initiate_creates_payment_and_returns_processing(): void
    {
        $this->fakeInitiateSuccess();
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);

        $payment = $this->service()->initiatePayment(
            delivery: $delivery,
            initiator: $distributor,
            phoneNumber: '255712345678',
            stage: Payment::STAGE_FULL,
        );

        $this->assertSame(Payment::STATUS_PROCESSING, $payment->status);
        $this->assertSame('CP-123', $payment->provider_reference);
        $this->assertSame('PAY-', substr($payment->reference, 0, 4));
        $this->assertSame(5000.0, (float) $payment->amount);
        $this->assertNotNull($payment->initiated_at);
    }

    public function test_initiate_writes_initiate_transaction(): void
    {
        $this->fakeInitiateSuccess();
        $delivery = $this->pendingDelivery($this->distributor());

        $payment = $this->service()->initiatePayment(
            $delivery, $delivery->distributor, '255712345678',
        );

        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'type'       => 'initiate',
            'status'     => 'success',
        ]);
    }

    // ─── Initiate: validation gates ──────────────────────────
    public function test_initiate_rejects_invalid_stage(): void
    {
        $delivery = $this->pendingDelivery($this->distributor());

        $this->expectException(PaymentException::class);
        $this->service()->initiatePayment(
            $delivery, $delivery->distributor, '255712345678', 'bogus',
        );
    }

    public function test_initiate_rejects_when_delivery_already_paid(): void
    {
        $delivery = $this->pendingDelivery($this->distributor(), [
            'status' => Delivery::STATUS_PAID,
        ]);

        $this->expectException(PaymentException::class);
        $this->service()->initiatePayment(
            $delivery, $delivery->distributor, '255712345678',
        );
    }

    public function test_initiate_rejects_duplicate_pending_payment(): void
    {
        $this->fakeInitiateSuccess();
        $delivery = $this->pendingDelivery($this->distributor());

        // First initiation
        $this->service()->initiatePayment(
            $delivery, $delivery->distributor, '255712345678',
        );

        // Second must be rejected (409-shaped exception)
        $this->expectException(PaymentException::class);
        $this->service()->initiatePayment(
            $delivery, $delivery->distributor, '255712345678',
        );
    }

    public function test_initiate_rejects_when_stage_already_completed(): void
    {
        $delivery = $this->pendingDelivery($this->distributor());
        Payment::factory()->create([
            'delivery_id'  => $delivery->id,
            'stage'        => Payment::STAGE_FULL,
            'status'       => Payment::STATUS_COMPLETED,
            'amount'       => 5000,
            'initiated_by' => $delivery->distributor_id,
            'initiated_at' => now(),
        ]);

        $this->expectException(PaymentException::class);
        $this->service()->initiatePayment(
            $delivery, $delivery->distributor, '255712345678',
        );
    }

    public function test_initiate_marks_payment_failed_when_provider_rejects(): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/initiate-ussd-push-request' => Http::response([
                'message' => 'Insufficient funds',
            ], 422),
        ]);

        $delivery = $this->pendingDelivery($this->distributor());

        try {
            $this->service()->initiatePayment(
                $delivery, $delivery->distributor, '255712345678',
            );
            $this->fail('Expected PaymentException');
        } catch (PaymentException $e) {
            $this->assertSame(502, $e->httpStatus);
        }

        $payment = Payment::where('delivery_id', $delivery->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertStringContainsString('Insufficient funds', $payment->failure_reason);
    }

    // ─── Sync: reconciliation ────────────────────────────────
    public function test_sync_marks_completed_and_pays_delivery(): void
    {
        $this->fakeInitiateSuccess();
        $delivery = $this->pendingDelivery($this->distributor());
        $payment = $this->service()->initiatePayment(
            $delivery, $delivery->distributor, '255712345678',
        );

        // Now ClickPesa says SUCCESS
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/PAY-*' => Http::response([
                'status' => 'SUCCESS',
                'amount' => '5000',
                'id'     => 'CP-123',
            ], 200),
        ]);

        $synced = $this->service()->syncPayment($payment);

        $this->assertSame(Payment::STATUS_COMPLETED, $synced->status);
        $this->assertNotNull($synced->paid_at);

        // Delivery should now be paid
        $this->assertSame(Delivery::STATUS_PAID, $delivery->fresh()->status);
    }

    public function test_sync_logs_verify_transaction(): void
    {
        $this->fakeInitiateSuccess();
        $delivery = $this->pendingDelivery($this->distributor());
        $payment = $this->service()->initiatePayment(
            $delivery, $delivery->distributor, '255712345678',
        );

        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/PAY-*' => Http::response(['status' => 'SUCCESS'], 200),
        ]);

        $this->service()->syncPayment($payment);

        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'type'       => 'verify',
        ]);
    }

    public function test_sync_is_noop_on_terminal_payment(): void
    {
        $delivery = $this->pendingDelivery($this->distributor());
        $payment = Payment::factory()->create([
            'delivery_id'  => $delivery->id,
            'stage'        => Payment::STAGE_FULL,
            'status'       => Payment::STATUS_COMPLETED,
            'amount'       => 5000,
            'initiated_by' => $delivery->distributor_id,
            'initiated_at' => now(),
            'paid_at'      => now(),
        ]);

        Http::fake(); // no calls should be made

        $synced = $this->service()->syncPayment($payment);

        $this->assertSame(Payment::STATUS_COMPLETED, $synced->status);
        Http::assertNothingSent();
    }

    // ─── Regional two-stage delivery ─────────────────────────
    public function test_delivery_paid_when_all_stages_complete(): void
    {
        $delivery = $this->pendingDelivery($this->distributor(), [
            'service_type'   => Delivery::SERVICE_REGIONAL,
            'ods_delivery_fee' => 2000,
            'bus_cargo_fare'   => 3500,
            'total_amount'     => 5500,
        ]);

        // Seed both payments in completed state (except we're testing transition via service)
        // Simulate: ods_fee completed, transport_fare completed → delivery → paid
        $delivery->update(['status' => Delivery::STATUS_PENDING_PAYMENT]);

        Payment::factory()->create([
            'delivery_id'  => $delivery->id,
            'stage'        => Payment::STAGE_ODS_FEE,
            'status'       => Payment::STATUS_COMPLETED,
            'amount'       => 2000,
            'initiated_by' => $delivery->distributor_id,
            'initiated_at' => now()->subMinutes(10),
            'paid_at'      => now()->subMinutes(9),
        ]);
        $transport = Payment::factory()->create([
            'delivery_id'  => $delivery->id,
            'stage'        => Payment::STAGE_TRANSPORT_FARE,
            'status'       => Payment::STATUS_PROCESSING,
            'amount'       => 3500,
            'initiated_by' => $delivery->distributor_id,
            'initiated_at' => now()->subMinute(),
        ]);

        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/PAY-*' => Http::response(['status' => 'SUCCESS'], 200),
        ]);

        $this->service()->syncPayment($transport);

        $this->assertSame(Payment::STATUS_COMPLETED, $transport->fresh()->status);
        $this->assertSame(Delivery::STATUS_PAID, $delivery->fresh()->status);
    }

    public function test_delivery_stays_pending_when_one_stage_outstanding(): void
    {
        $delivery = $this->pendingDelivery($this->distributor(), [
            'service_type'   => Delivery::SERVICE_REGIONAL,
            'ods_delivery_fee' => 2000,
            'bus_cargo_fare'   => 3500,
            'total_amount'     => 5500,
        ]);

        Payment::factory()->create([
            'delivery_id'  => $delivery->id,
            'stage'        => Payment::STAGE_ODS_FEE,
            'status'       => Payment::STATUS_COMPLETED,
            'amount'       => 2000,
            'initiated_by' => $delivery->distributor_id,
            'initiated_at' => now()->subMinutes(10),
            'paid_at'      => now()->subMinutes(9),
        ]);
        $transport = Payment::factory()->create([
            'delivery_id'  => $delivery->id,
            'stage'        => Payment::STAGE_TRANSPORT_FARE,
            'status'       => Payment::STATUS_PROCESSING,
            'amount'       => 3500,
            'initiated_by' => $delivery->distributor_id,
            'initiated_at' => now()->subMinute(),
        ]);

        // Provider says still PENDING
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/PAY-*' => Http::response(['status' => 'PENDING'], 200),
        ]);

        $this->service()->syncPayment($transport);

        // The fake provider returned PENDING; PaymentStatusMapper maps
        // that to Payment::STATUS_PENDING. The sync therefore downgrades
        // the seeded PROCESSING to PENDING (matches ClickPesa's state).
        $this->assertSame(Payment::STATUS_PENDING, $transport->fresh()->status);
        $this->assertSame(Delivery::STATUS_PENDING_PAYMENT, $delivery->fresh()->status);
    }
}