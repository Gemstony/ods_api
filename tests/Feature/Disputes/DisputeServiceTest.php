<?php

namespace Tests\Feature\Disputes;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Dispute;
use App\Models\Role;
use App\Models\User;
use App\Services\Disputes\DisputeException;
use App\Services\Disputes\DisputeService;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisputeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        (new SettingsSeeder())->run();
    }

    protected function service(): DisputeService
    {
        return app(DisputeService::class);
    }

    protected function owner(): User
    {
        return User::factory()->role(Role::OWNER)->create();
    }

    protected function distributor(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create();
    }

    protected function manager(Branch $branch): User
    {
        return User::factory()->role(Role::MANAGER)->create(['branch_id' => $branch->id]);
    }

    protected function rider(): User
    {
        return User::factory()->role(Role::RIDER)->create();
    }

    protected function delivery(
        ?User $distributor = null,
        ?User $rider = null,
        ?Branch $branch = null,
    ): Delivery {
        return Delivery::factory()->create([
            'distributor_id' => ($distributor ?? $this->distributor())->id,
            'rider_id' => $rider?->id,
            'pickup_branch_id' => ($branch ?? Branch::factory()->create())->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_DELIVERED,
        ]);
    }

    // ─── open() ─────────────────────────────────────────────
    public function test_distributor_can_open_dispute_about_own_delivery(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->delivery(distributor: $distributor);

        $dispute = $this->service()->open(
            opener: $distributor,
            deliveryId: $delivery->id,
            category: Dispute::CATEGORY_DELIVERY,
            subject: 'Package was damaged',
            description: 'The package arrived with a crushed corner.',
        );

        $this->assertSame(Dispute::STATUS_OPEN, $dispute->status);
        $this->assertSame(Dispute::PRIORITY_NORMAL, $dispute->priority);
        $this->assertDatabaseHas('disputes', [
            'id' => $dispute->id,
            'opened_by' => $distributor->id,
            'delivery_id' => $delivery->id,
        ]);
    }

    public function test_distributor_cannot_open_dispute_about_others_delivery(): void
    {
        $me = $this->distributor();
        $other = $this->distributor();
        $delivery = $this->delivery(distributor: $other);

        $this->expectException(DisputeException::class);
        $this->service()->open(
            opener: $me,
            deliveryId: $delivery->id,
            category: Dispute::CATEGORY_DELIVERY,
            subject: 'Test',
            description: 'Test description that is long enough.',
        );
    }

    public function test_manager_can_open_about_branch_delivery(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $delivery = $this->delivery(branch: $branch);

        $dispute = $this->service()->open(
            opener: $manager,
            deliveryId: $delivery->id,
            category: Dispute::CATEGORY_PACKAGE,
            subject: 'Package missing',
            description: 'The package cannot be located at the branch.',
        );

        $this->assertSame($manager->id, $dispute->opened_by);
    }

    public function test_manager_cannot_open_about_other_branch(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $delivery = $this->delivery(branch: $otherBranch);

        $this->expectException(DisputeException::class);
        $this->service()->open(
            opener: $manager,
            deliveryId: $delivery->id,
            category: Dispute::CATEGORY_PACKAGE,
            subject: 'Test',
            description: 'Test description that is long enough.',
        );
    }

    public function test_rider_can_open_about_assigned_delivery(): void
    {
        $rider = $this->rider();
        $delivery = $this->delivery(rider: $rider);

        $dispute = $this->service()->open(
            opener: $rider,
            deliveryId: $delivery->id,
            category: Dispute::CATEGORY_DELIVERY,
            subject: 'Recipient not available',
            description: 'No one was home when I arrived.',
        );

        $this->assertSame($rider->id, $dispute->opened_by);
    }

    public function test_rider_cannot_open_about_others_delivery(): void
    {
        $me = $this->rider();
        $other = $this->rider();
        $delivery = $this->delivery(rider: $other);

        $this->expectException(DisputeException::class);
        $this->service()->open(
            opener: $me,
            deliveryId: $delivery->id,
            category: Dispute::CATEGORY_DELIVERY,
            subject: 'Test',
            description: 'Test description that is long enough.',
        );
    }

    public function test_owner_can_open_about_any_delivery(): void
    {
        $owner = $this->owner();
        $delivery = $this->delivery();

        $dispute = $this->service()->open(
            opener: $owner,
            deliveryId: $delivery->id,
            category: Dispute::CATEGORY_OTHER,
            subject: 'Compliance review',
            description: 'Opened by Owner to track a compliance issue.',
        );

        $this->assertSame($owner->id, $dispute->opened_by);
    }

    // ─── resolve() / close() / escalate() / startReview() ───
    public function test_owner_can_resolve_open_dispute(): void
    {
        $dispute = $this->makeDispute();
        $owner = $this->owner();

        $resolved = $this->service()->resolve($owner, $dispute, 'Refunded 5000 TZS.');

        $this->assertSame(Dispute::STATUS_RESOLVED, $resolved->status);
        $this->assertSame('Refunded 5000 TZS.', $resolved->resolution);
        $this->assertSame($owner->id, $resolved->resolved_by);
        $this->assertNotNull($resolved->resolved_at);
    }

    public function test_owner_can_close_open_dispute(): void
    {
        $dispute = $this->makeDispute();
        $owner = $this->owner();

        $closed = $this->service()->close($owner, $dispute, 'Insufficient evidence.');

        $this->assertSame(Dispute::STATUS_CLOSED, $closed->status);
        $this->assertSame('Insufficient evidence.', $closed->resolution);
    }

    public function test_owner_can_escalate_dispute(): void
    {
        $dispute = $this->makeDispute();
        $owner = $this->owner();

        $escalated = $this->service()->escalate($owner, $dispute);

        $this->assertSame(Dispute::STATUS_ESCALATED, $escalated->status);
        $this->assertNull($escalated->resolved_at);
    }

    public function test_owner_can_start_review(): void
    {
        $dispute = $this->makeDispute();
        $owner = $this->owner();

        $reviewing = $this->service()->startReview($owner, $dispute);

        $this->assertSame(Dispute::STATUS_IN_REVIEW, $reviewing->status);
    }

    public function test_cannot_transition_resolved_dispute(): void
    {
        $dispute = $this->makeDispute();
        $owner = $this->owner();
        $this->service()->resolve($owner, $dispute, 'Handled.');

        $this->expectException(DisputeException::class);
        $this->service()->resolve($owner, $dispute->fresh(), 'Try again.');
    }

    public function test_cannot_transition_closed_dispute(): void
    {
        $dispute = $this->makeDispute();
        $owner = $this->owner();
        $this->service()->close($owner, $dispute);

        $this->expectException(DisputeException::class);
        $this->service()->escalate($owner, $dispute->fresh());
    }

    // ─── Notifications (side-effect check) ──────────────────
    public function test_opening_dispute_notifies_owner(): void
    {
        $owner = $this->owner();
        $distributor = $this->distributor();
        $delivery = $this->delivery(distributor: $distributor);

        $this->service()->open(
            opener: $distributor,
            deliveryId: $delivery->id,
            category: Dispute::CATEGORY_DELIVERY,
            subject: 'Test',
            description: 'Test description that is long enough.',
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'dispute.opened',
        ]);
    }

    public function test_resolving_dispute_notifies_opener(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->delivery(distributor: $distributor);
        $dispute = $this->service()->open(
            opener: $distributor,
            deliveryId: $delivery->id,
            category: Dispute::CATEGORY_DELIVERY,
            subject: 'Test',
            description: 'Test description that is long enough.',
        );
        $owner = $this->owner();

        $this->service()->resolve($owner, $dispute, 'Handled.');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $distributor->id,
            'type' => 'dispute.resolved',
        ]);
    }

    // ─── Helper ─────────────────────────────────────────────
    private function makeDispute(): Dispute
    {
        $distributor = $this->distributor();
        $delivery = $this->delivery(distributor: $distributor);

        return $this->service()->open(
            opener: $distributor,
            deliveryId: $delivery->id,
            category: Dispute::CATEGORY_DELIVERY,
            subject: 'Test subject',
            description: 'Test description that is long enough.',
        );
    }
}