<?php

namespace Tests\Feature\ManualPayments;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualPaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        (new SettingsSeeder())->run();
    }

    protected function distributor(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create();
    }

    protected function manager(Branch $branch): User
    {
        return User::factory()->role(Role::MANAGER)->create(['branch_id' => $branch->id]);
    }

    protected function delivery(User $distributor, Branch $branch): Delivery
    {
        return Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PENDING_PAYMENT,
            'total_amount' => 5000,
            'currency' => 'TZS',
        ]);
    }

    protected function makePendingManualPayment(Delivery $delivery, User $distributor): Payment
    {
        return Payment::create([
            'delivery_id' => $delivery->id,
            'stage' => Payment::STAGE_FULL,
            'amount' => 5000,
            'currency' => 'TZS',
            'method' => Payment::METHOD_CASH,
            'status' => Payment::STATUS_PENDING,
            'initiated_by' => $distributor->id,
            'initiated_at' => now(),
        ]);
    }

    // ─── Distributor manual ─────────────────────────────────
    public function test_distributor_manual_creates_pending_payment(): void
    {
        $distributor = $this->distributor();
        $branch = Branch::factory()->create();
        $delivery = $this->delivery($distributor, $branch);

        $response = $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/payments/manual', [
                'delivery_id' => $delivery->id,
                'method' => 'cash',
                'notes' => 'Paid at branch reception',
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.method', 'cash')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.delivery_status', 'pending_payment');
    }

    public function test_distributor_manual_rejects_when_pending_payment_exists(): void
    {
        $distributor = $this->distributor();
        $branch = Branch::factory()->create();
        $delivery = $this->delivery($distributor, $branch);
        $this->makePendingManualPayment($delivery, $distributor);

        $this->actingAs($distributor, 'api')
            ->postJson('/api/v1/distributor/payments/manual', [
                'delivery_id' => $delivery->id,
                'method' => 'cash',
            ])
            ->assertStatus(409);
    }

    // ─── Manager approval ───────────────────────────────────
    public function test_manager_pending_index_lists_manual_payments(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $distributor = $this->distributor();
        $delivery = $this->delivery($distributor, $branch);
        $this->makePendingManualPayment($delivery, $distributor);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/payments/pending');

        $response->assertStatus(200)->assertJsonPath('data.total', 1);
    }

    public function test_manager_approve_completes_payment_and_delivery(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $distributor = $this->distributor();
        $delivery = $this->delivery($distributor, $branch);
        $payment = $this->makePendingManualPayment($delivery, $distributor);

        $response = $this->actingAs($manager, 'api')
            ->postJson("/api/v1/manager/payments/{$payment->id}/approve");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(
            Delivery::STATUS_PAID,
            $delivery->fresh()->status,
        );
    }

    public function test_manager_reject_sets_failed_and_keeps_delivery_pending(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $distributor = $this->distributor();
        $delivery = $this->delivery($distributor, $branch);
        $payment = $this->makePendingManualPayment($delivery, $distributor);

        $response = $this->actingAs($manager, 'api')
            ->postJson("/api/v1/manager/payments/{$payment->id}/reject", [
                'reason' => 'No cash received at branch.',
            ]);

        $response->assertStatus(200)->assertJsonPath('data.status', 'failed');

        $this->assertSame(
            Delivery::STATUS_PENDING_PAYMENT,
            $delivery->fresh()->status,
        );
    }

    public function test_manager_cannot_approve_other_branch_payment(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $managerA = $this->manager($branchA);
        $distributor = $this->distributor();
        $delivery = $this->delivery($distributor, $branchB);
        $payment = $this->makePendingManualPayment($delivery, $distributor);

        $this->actingAs($managerA, 'api')
            ->postJson("/api/v1/manager/payments/{$payment->id}/approve")
            ->assertStatus(404);
    }

    public function test_reject_requires_reason(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $distributor = $this->distributor();
        $delivery = $this->delivery($distributor, $branch);
        $payment = $this->makePendingManualPayment($delivery, $distributor);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/manager/payments/{$payment->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    // ─── Owner fallback (Send delivery / no branch) ─────────
    public function test_owner_can_approve_any_manual_payment(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();
        $branch = Branch::factory()->create();
        $distributor = $this->distributor();
        $delivery = $this->delivery($distributor, $branch);
        $payment = $this->makePendingManualPayment($delivery, $distributor);

        $response = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/payments/{$payment->id}/approve");

        $response->assertStatus(200)->assertJsonPath('data.status', 'completed');
    }
}