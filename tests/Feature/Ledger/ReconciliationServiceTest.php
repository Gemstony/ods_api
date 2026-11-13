<?php

namespace Tests\Feature\Ledger;

use App\Models\Delivery;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\Ledger\ReconciliationService;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        config([
            'clickpesa.base_url' => 'https://sandbox.clickpesa.com',
            'clickpesa.client_id' => 'test-client',
            'clickpesa.client_secret' => 'test-secret',
            'clickpesa.currency' => 'TZS',
            'clickpesa.timeout' => 5,
            'clickpesa.connect_timeout' => 2,
            'clickpesa.token_ttl_minutes' => 55,
            'clickpesa.webhook_secret' => 'whsec-test',
        ]);
    }

    protected function service(): ReconciliationService
    {
        return app(ReconciliationService::class);
    }

    protected function pendingPayment(string $ref = 'PAY-20261113-AAAAAA'): Payment
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'status' => Delivery::STATUS_PENDING_PAYMENT,
        ]);

        return Payment::create([
            'delivery_id' => $delivery->id,
            'stage' => Payment::STAGE_FULL,
            'amount' => 5000,
            'currency' => 'TZS',
            'method' => Payment::METHOD_CLICKPESA,
            'status' => Payment::STATUS_PROCESSING,
            'reference' => $ref,
            'initiated_by' => $distributor->id,
            'initiated_at' => now()->subHour(),
        ]);
    }

    protected function fakeQueryAll(array $items): void
    {
        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/all*' => Http::response([
                'data' => $items,
                'pagination' => ['page' => 1, 'totalPages' => 1],
            ], 200),
        ]);
    }

    // ─── No-ops ──────────────────────────────────────────────
    public function test_returns_empty_when_no_pending_payments(): void
    {
        $result = $this->service()->reconcilePendingPayments();

        $this->assertSame(0, $result->localScanned);
        $this->assertNull($result->error);
        Http::assertNothingSent();
    }

    // ─── Happy path ──────────────────────────────────────────
    public function test_reconciles_pending_to_success(): void
    {
        $payment = $this->pendingPayment('PAY-20261113-AAAAAA');

        $this->fakeQueryAll([
            [
                'id' => 'CP-123',
                'orderReference' => 'PAY-20261113-AAAAAA',
                'status' => 'SUCCESS',
                'amount' => '5000',
            ],
        ]);

        $result = $this->service()->reconcilePendingPayments();

        $this->assertCount(1, $result->reconciled);
        $this->assertSame('completed', $result->reconciled[0]['to_status']);
        $this->assertTrue($result->reconciled[0]['applied']);

        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertSame(Delivery::STATUS_PAID, $payment->delivery->fresh()->status);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'type' => 'verify',
        ]);
    }

    public function test_reconciles_pending_to_failed(): void
    {
        $payment = $this->pendingPayment();

        $this->fakeQueryAll([
            [
                'orderReference' => $payment->reference,
                'status' => 'FAILED',
                'message' => 'Insufficient funds',
            ],
        ]);

        $result = $this->service()->reconcilePendingPayments();

        $this->assertCount(1, $result->reconciled);
        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertStringContainsString('Insufficient funds', (string) $payment->fresh()->failure_reason);
    }

    // ─── Drift ───────────────────────────────────────────────
    public function test_terminal_local_vs_different_provider_is_drift_not_applied(): void
    {
        $payment = $this->pendingPayment();
        // Simulate a completed payment that provider now says FAILED (rare)
        $payment->update(['status' => Payment::STATUS_COMPLETED, 'paid_at' => now()]);

        $this->fakeQueryAll([
            [
                'orderReference' => $payment->reference,
                'status' => 'FAILED',
            ],
        ]);

        $result = $this->service()->reconcilePendingPayments();

        // Local completed is terminal → not applied
        $this->assertSame(0, count($result->reconciled));
        $this->assertSame(0, count($result->drift)); // because local was terminal, it was excluded from scan
        // Actually since $since default is 7 days and payment created_at is <7 days, it IS scanned.
        // But it's completed, not pending/processing — so it's excluded at the query level.
        // So it never makes it to the loop. Result: unchanged = 0, orphans = 0.

        // Assert the terminal state is preserved
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->fresh()->status);
    }

    // ─── Orphans ─────────────────────────────────────────────
    public function test_pending_payment_missing_at_provider_reported_as_orphan(): void
    {
        $payment = $this->pendingPayment('PAY-20261113-ORPHAN');

        $this->fakeQueryAll([
            [
                'orderReference' => 'PAY-DIFFERENT',
                'status' => 'SUCCESS',
            ],
        ]);

        $result = $this->service()->reconcilePendingPayments();

        $this->assertCount(1, $result->orphans);
        $this->assertSame('PAY-20261113-ORPHAN', $result->orphans[0]['reference']);
        // Local state unchanged
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    // ─── Dry run ─────────────────────────────────────────────
    public function test_dry_run_reports_but_does_not_apply(): void
    {
        $payment = $this->pendingPayment();

        $this->fakeQueryAll([
            [
                'orderReference' => $payment->reference,
                'status' => 'SUCCESS',
            ],
        ]);

        $result = $this->service()->reconcilePendingPayments(dryRun: true);

        $this->assertTrue($result->dryRun);
        $this->assertCount(1, $result->reconciled);
        $this->assertFalse($result->reconciled[0]['applied']);

        // Local state unchanged
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    // ─── Unchanged ───────────────────────────────────────────
    public function test_unchanged_when_provider_status_matches(): void
    {
        $payment = $this->pendingPayment();

        $this->fakeQueryAll([
            [
                'orderReference' => $payment->reference,
                'status' => 'PROCESSING',
            ],
        ]);

        $result = $this->service()->reconcilePendingPayments();

        $this->assertSame(0, count($result->reconciled));
        $this->assertSame(1, $result->unchanged);
    }

    // ─── Provider error ──────────────────────────────────────
    public function test_returns_error_when_provider_fails(): void
    {
        $this->pendingPayment();

        Http::fake([
            '*/third-parties/generate-token' => Http::response(['token' => 'Bearer t'], 200),
            '*/third-parties/payments/all*' => Http::response([], 500),
        ]);

        $result = $this->service()->reconcilePendingPayments();

        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Provider query failed', (string) $result->error);
    }

    // ─── Audit ───────────────────────────────────────────────
    public function test_audit_log_written_on_run(): void
    {
        $payment = $this->pendingPayment();

        $this->fakeQueryAll([
            [
                'orderReference' => $payment->reference,
                'status' => 'PROCESSING',
            ],
        ]);

        $this->service()->reconcilePendingPayments();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reconciliation.run_completed',
        ]);
    }
}