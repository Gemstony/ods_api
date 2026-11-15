<?php

namespace Tests\Feature\ManualPayments;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        (new SettingsSeeder())->run();
    }

    protected function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    protected function distributor(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create();
    }

    protected function manager(Branch $branch): User
    {
        return User::factory()->role(Role::MANAGER)->create(['branch_id' => $branch->id]);
    }

    protected function pendingDelivery(?User $distributor = null): Delivery
    {
        return Delivery::factory()->create([
            'distributor_id' => ($distributor ?? $this->distributor())->id,
            'pickup_branch_id' => Branch::factory()->create()->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PENDING_PAYMENT,
            'total_amount' => 5000,
            'ods_delivery_fee' => 5000,
            'currency' => 'TZS',
        ]);
    }

    // ─── initiate ──────────────────────────────────────────
    public function test_initiate_creates_pending_manual_payment(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);

        $payment = $this->service()->initiateManualPayment(
            delivery: $delivery,
            initiator: $distributor,
            method: Payment::METHOD_CASH,
        );

        $this->assertSame(Payment::METHOD_CASH, $payment->method);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame(5000.0, (float) $payment->amount);
        $this->assertNull($payment->expires_at);
        $this->assertSame(
            Delivery::STATUS_PENDING_PAYMENT,
            $delivery->fresh()->status,
        );
    }

    public function test_initiate_rejects_when_not_pending_payment(): void
    {
        $delivery = $this->pendingDelivery();
        $delivery->update(['status' => Delivery::STATUS_PAID]);

        $this->expectException(PaymentException::class);
        $this->service()->initiateManualPayment(
            delivery: $delivery->fresh(),
            initiator: $delivery->distributor,
            method: Payment::METHOD_CASH,
        );
    }

    public function test_initiate_blocks_when_pending_clickpesa_exists(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);

        Payment::create([
            'delivery_id' => $delivery->id,
            'stage' => Payment::STAGE_FULL,
            'amount' => 5000,
            'currency' => 'TZS',
            'method' => Payment::METHOD_CLICKPESA,
            'status' => Payment::STATUS_PROCESSING,
            'initiated_by' => $distributor->id,
            'initiated_at' => now(),
        ]);

        $this->expectException(PaymentException::class);
        $this->service()->initiateManualPayment(
            delivery: $delivery,
            initiator: $distributor,
            method: Payment::METHOD_CASH,
        );
    }

    public function test_initiate_rejects_invalid_method(): void
    {
        $delivery = $this->pendingDelivery();

        $this->expectException(PaymentException::class);
        $this->service()->initiateManualPayment(
            delivery: $delivery,
            initiator: $delivery->distributor,
            method: 'clickpesa', // not allowed on the manual endpoint
        );
    }

    // ─── approve ───────────────────────────────────────────
    public function test_approve_marks_completed_and_pays_delivery(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);
        $branch = Branch::find($delivery->pickup_branch_id);
        $manager = $this->manager($branch);

        $payment = $this->service()->initiateManualPayment(
            delivery: $delivery,
            initiator: $distributor,
            method: Payment::METHOD_CASH,
        );

        $approved = $this->service()->approveManualPayment($manager, $payment);

        $this->assertSame(Payment::STATUS_COMPLETED, $approved->status);
        $this->assertNotNull($approved->paid_at);
        $this->assertSame(
            Delivery::STATUS_PAID,
            $delivery->fresh()->status,
        );
    }

    public function test_approve_409s_on_non_pending_payment(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);
        $branch = Branch::find($delivery->pickup_branch_id);
        $manager = $this->manager($branch);

        $payment = $this->service()->initiateManualPayment(
            delivery: $delivery,
            initiator: $distributor,
            method: Payment::METHOD_CASH,
        );
        $this->service()->approveManualPayment($manager, $payment);

        $this->expectException(PaymentException::class);
        $this->service()->approveManualPayment($manager, $payment->fresh());
    }

    public function test_approve_rejects_clickpesa_payment(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);
        $branch = Branch::find($delivery->pickup_branch_id);
        $manager = $this->manager($branch);

        $payment = Payment::create([
            'delivery_id' => $delivery->id,
            'stage' => Payment::STAGE_FULL,
            'amount' => 5000,
            'currency' => 'TZS',
            'method' => Payment::METHOD_CLICKPESA,
            'status' => Payment::STATUS_PENDING,
            'initiated_by' => $distributor->id,
            'initiated_at' => now(),
        ]);

        $this->expectException(PaymentException::class);
        $this->service()->approveManualPayment($manager, $payment);
    }

    // ─── reject ────────────────────────────────────────────
    public function test_reject_marks_failed_and_keeps_delivery_pending(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);
        $branch = Branch::find($delivery->pickup_branch_id);
        $manager = $this->manager($branch);

        $payment = $this->service()->initiateManualPayment(
            delivery: $delivery,
            initiator: $distributor,
            method: Payment::METHOD_CASH,
        );

        $rejected = $this->service()->rejectManualPayment(
            $manager,
            $payment,
            'Cash not received at branch.',
        );

        $this->assertSame(Payment::STATUS_FAILED, $rejected->status);
        $this->assertNotNull($rejected->failed_at);
        $this->assertSame(
            'Cash not received at branch.',
            $rejected->failure_reason,
        );
        $this->assertSame(
            Delivery::STATUS_PENDING_PAYMENT,
            $delivery->fresh()->status,
        );
    }

    public function test_reject_then_distributor_can_initiate_new(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);
        $branch = Branch::find($delivery->pickup_branch_id);
        $manager = $this->manager($branch);

        $first = $this->service()->initiateManualPayment(
            delivery: $delivery,
            initiator: $distributor,
            method: Payment::METHOD_CASH,
        );
        $this->service()->rejectManualPayment($manager, $first, 'No cash received.');

        $second = $this->service()->initiateManualPayment(
            delivery: $delivery->fresh(),
            initiator: $distributor,
            method: Payment::METHOD_CASH,
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(Payment::STATUS_PENDING, $second->status);
    }

    // ─── Notifications ─────────────────────────────────────
    public function test_approve_notifies_distributor(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);
        $branch = Branch::find($delivery->pickup_branch_id);
        $manager = $this->manager($branch);

        $payment = $this->service()->initiateManualPayment(
            delivery: $delivery,
            initiator: $distributor,
            method: Payment::METHOD_CASH,
        );
        $this->service()->approveManualPayment($manager, $payment);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $distributor->id,
            'type' => 'payment.completed',
        ]);
    }

    public function test_reject_notifies_distributor(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->pendingDelivery($distributor);
        $branch = Branch::find($delivery->pickup_branch_id);
        $manager = $this->manager($branch);

        $payment = $this->service()->initiateManualPayment(
            delivery: $delivery,
            initiator: $distributor,
            method: Payment::METHOD_CASH,
        );
        $this->service()->rejectManualPayment($manager, $payment, 'No cash.');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $distributor->id,
            'type' => 'payment.failed',
        ]);
    }
}