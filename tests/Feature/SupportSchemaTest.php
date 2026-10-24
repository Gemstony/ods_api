<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\Dispute;
use App\Models\Notification;
use App\Models\Reward;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupportSchemaTest extends TestCase
{
    use RefreshDatabase;

    // ─── Tables ────────────────────────────────────────────────
    public function test_all_support_tables_exist(): void
    {
        foreach (['audit_logs', 'notifications', 'rewards', 'disputes'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: $table");
        }
    }

    // ─── Audit logs ────────────────────────────────────────────
    public function test_audit_log_record_snapshots_actor_and_subject(): void
    {
        $actor = User::factory()->role(Role::OWNER)->create();
        $target = User::factory()->role(Role::MANAGER)->create();

        $log = AuditLog::record(
            actor: $actor,
            action: 'user.role_changed',
            description: 'Changed manager role',
            subject: $target,
            changes: ['role' => ['from' => 'distributor', 'to' => 'manager']],
        );

        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame('owner', $log->actor_role);
        $this->assertSame($actor->name, $log->actor_label);
        $this->assertSame($target->getMorphClass(), $log->subject_type);
        $this->assertSame($target->id, $log->subject_id);
        $this->assertSame($target->name, $log->subject_label);
        $this->assertSame(['role' => ['from' => 'distributor', 'to' => 'manager']], $log->changes);
    }

    public function test_audit_log_survives_actor_deletion(): void
    {
        $actor = User::factory()->role(Role::OWNER)->create();
        $log = AuditLog::record($actor, 'test.action', 'Test');
        $actor->forceDelete();

        $fresh = $log->fresh();
        $this->assertNull($fresh->actor_id);          // FK set null
        $this->assertSame('owner', $fresh->actor_role); // snapshot preserved
        $this->assertNotNull($fresh->actor_label);
    }

    // ─── Notifications ─────────────────────────────────────────
    public function test_notification_send_creates_record(): void
    {
        $user = User::factory()->create();
        $notification = Notification::send(
            user: $user,
            type: 'delivery.assigned',
            title: 'New delivery assigned',
            body: 'You have been assigned delivery ODS-20261024-ABC123',
            data: ['delivery_id' => 42],
            channels: ['in_app', 'sms'],
            priority: Notification::PRIORITY_HIGH,
        );

        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame('delivery.assigned', $notification->type);
        $this->assertSame(['in_app', 'sms'], $notification->channels);
        $this->assertSame(['delivery_id' => 42], $notification->data);
        $this->assertSame(Notification::STATUS_PENDING, $notification->status);
    }

    public function test_notification_mark_as_read(): void
    {
        $n = Notification::factory()->sent()->create();
        $this->assertFalse($n->isRead());

        $n->markAsRead();
        $this->assertTrue($n->fresh()->isRead());
        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_notification_unread_scope(): void
    {
        $user = User::factory()->create();
        Notification::factory()->for($user)->count(3)->create();
        Notification::factory()->for($user)->read()->create();

        $this->assertSame(3, Notification::forUser($user->id)->unread()->count());
    }

    // ─── Rewards ───────────────────────────────────────────────
    public function test_reward_for_creates_singleton_per_distributor(): void
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $a = Reward::for($distributor);
        $b = Reward::for($distributor);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Reward::where('distributor_id', $distributor->id)->count());
    }

    public function test_reward_unlocks_tier1_at_20_deliveries(): void
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $reward = Reward::for($distributor);

        // Simulate 19 deliveries — should not unlock
        $reward->update(['monthly_count' => 19]);

        // Simulate 20th delivery via recordSuccessfulDelivery
        Delivery::factory()->delivered()->create([
            'distributor_id'       => $distributor->id,
            'service_type'         => Delivery::SERVICE_RECEIVE,
            'status'               => Delivery::STATUS_DELIVERED,
            'delivered_at'         => now(),
        ]);

        $reward->recordSuccessfulDelivery(
            tier1Threshold: 20,
            tier2Threshold: 50,
            rollingWindowDays: 30,
        );

        $fresh = $reward->fresh();
        $this->assertSame(20, $fresh->monthly_count);
        $this->assertTrue($fresh->tier1_available);
        $this->assertNotNull($fresh->tier1_reached_at);
        $this->assertNotNull($fresh->tier1_expires_at);
    }

    public function test_redeeming_tier1_subtracts_threshold_and_reevaluates(): void
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $reward = Reward::for($distributor)->fresh();
        $reward->update([
            'monthly_count'    => 40, // enough for two rewards
            'tier1_available'  => true,
            'tier1_reached_at' => now(),
            'tier1_expires_at' => now()->addDays(30),
        ]);

        $this->assertTrue($reward->redeemTier1(tier1Threshold: 20));

        $fresh = $reward->fresh();
        $this->assertSame(20, $fresh->monthly_count);
        // Still meets threshold → immediately available again
        $this->assertTrue($fresh->tier1_available);
    }

    public function test_reward_monthly_reset_on_rollover(): void
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $reward = Reward::for($distributor)->fresh();
        $reward->update([
            'current_month' => '2025-01', // previous month
            'monthly_count' => 15,
        ]);

        Delivery::factory()->delivered()->create([
            'distributor_id' => $distributor->id,
            'status'         => Delivery::STATUS_DELIVERED,
            'delivered_at'   => now(),
        ]);

        $reward->recordSuccessfulDelivery(20, 50, 30);

        $fresh = $reward->fresh();
        $this->assertSame(now()->format('Y-m'), $fresh->current_month);
        $this->assertSame(1, $fresh->monthly_count); // reset then incremented
    }

    // ─── Disputes ──────────────────────────────────────────────
    public function test_dispute_relations_and_helpers(): void
    {
        $opener = User::factory()->create();
        $delivery = Delivery::factory()->create();
        $dispute = Dispute::factory()->create([
            'opened_by'   => $opener->id,
            'delivery_id' => $delivery->id,
            'category'    => Dispute::CATEGORY_DELIVERY,
        ]);

        $this->assertSame($opener->id, $dispute->openedBy->id);
        $this->assertSame($delivery->id, $dispute->delivery->id);
        $this->assertTrue($dispute->isOpen());
        $this->assertTrue($dispute->isActive());
        $this->assertFalse($dispute->isResolved());
    }

    public function test_dispute_resolved_state(): void
    {
        $dispute = Dispute::factory()->resolved()->create([
            'resolved_by' => User::factory(),
        ]);

        $this->assertTrue($dispute->isResolved());
        $this->assertFalse($dispute->isActive());
        $this->assertNotNull($dispute->resolved_at);
        $this->assertNotNull($dispute->resolution);
    }

    public function test_dispute_open_scope(): void
    {
        Dispute::factory()->count(3)->create();
        Dispute::factory()->resolved()->create();

        $this->assertSame(3, Dispute::open()->count());
    }

    // ─── User relations ────────────────────────────────────────
    public function test_user_new_relations(): void
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();

        Notification::factory()->for($distributor)->create();
        Reward::for($distributor);
        Dispute::factory()->create(['opened_by' => $distributor->id]);
        AuditLog::record($distributor, 'test.action', 'Test');

        $distributor = $distributor->fresh();
        $this->assertCount(1, $distributor->notifications);
        $this->assertNotNull($distributor->reward);
        $this->assertCount(1, $distributor->openedDisputes);
        $this->assertCount(1, $distributor->auditLogsAsActor);
    }
}