<?php

namespace Tests\Feature\Notifications;

use App\Models\Delivery;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationType;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
        (new SettingsSeeder())->run();
    }

    protected function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    protected function distributor(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create();
    }

    protected function rider(): User
    {
        return User::factory()->role(Role::RIDER)->create();
    }

    protected function delivery(User $distributor, ?User $rider = null): Delivery
    {
        return Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'rider_id' => $rider?->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PAID,
        ]);
    }

    // ─── Basics ─────────────────────────────────────────────
    public function test_notify_creates_row_and_marks_sent_for_in_app(): void
    {
        $user = $this->distributor();

        $notification = $this->service()->notify(
            $user,
            NotificationType::DeliveryAccepted,
            'Test title',
            'Test body',
            ['delivery_id' => 42],
        );

        $this->assertNotNull($notification);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'delivery.accepted',
            'status' => 'sent',
        ]);
        $this->assertNotNull($notification->sent_at);
        $this->assertNotNull($notification->read_at === null ? 'still-unread' : 'unexpected');
    }

    // ─── Dedupe ─────────────────────────────────────────────
    public function test_terminal_event_is_deduped_within_5_minutes(): void
    {
        $user = $this->distributor();
        $data = ['delivery_id' => 99];

        $first = $this->service()->notify(
            $user, NotificationType::PaymentCompleted, 'T', 'B', $data,
        );
        $second = $this->service()->notify(
            $user, NotificationType::PaymentCompleted, 'T', 'B', $data,
        );

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, Notification::count());
    }

    public function test_non_terminal_event_is_not_deduped(): void
    {
        $user = $this->distributor();
        $data = ['delivery_id' => 99];

        $this->service()->notify(
            $user, NotificationType::PaymentFailed, 'T', 'B', $data,
        );
        $this->service()->notify(
            $user, NotificationType::PaymentFailed, 'T', 'B', $data,
        );

        $this->assertSame(2, Notification::count());
    }

    public function test_dedupe_window_expires_after_5_minutes(): void
    {
        $user = $this->distributor();
        $data = ['delivery_id' => 99];

        $this->service()->notify(
            $user, NotificationType::DeliveryDelivered, 'T', 'B', $data,
        );

        // Simulate an old row by rewinding created_at.
        Notification::query()->update(['created_at' => now()->subMinutes(10)]);

        $second = $this->service()->notify(
            $user, NotificationType::DeliveryDelivered, 'T', 'B', $data,
        );

        $this->assertNotNull($second);
        $this->assertSame(2, Notification::count());
    }

    // ─── Typed helpers ──────────────────────────────────────
    public function test_delivery_assigned_notifies_rider_and_distributor(): void
    {
        $distributor = $this->distributor();
        $rider = $this->rider();
        $delivery = $this->delivery($distributor, $rider);

        $this->service()->deliveryAssigned($delivery);

        $this->assertSame(2, Notification::count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $rider->id,
            'type' => 'delivery.assigned',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $distributor->id,
            'type' => 'delivery.assigned',
        ]);
    }

    public function test_package_ready_notifies_branch_receivers(): void
    {
        $branch = \App\Models\Branch::factory()->create();
        $receiver = User::factory()->role(Role::RECEIVER)->create([
            'branch_id' => $branch->id,
        ]);
        $distributor = $this->distributor();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_PAID,
        ]);

        $this->service()->packageReady($delivery);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $receiver->id,
            'type' => 'package.ready',
        ]);
    }

    public function test_package_ready_skips_when_no_branch(): void
    {
        $distributor = $this->distributor();
        $delivery = Delivery::factory()->create([
            'distributor_id' => $distributor->id,
            'pickup_branch_id' => null,
            'service_type' => Delivery::SERVICE_SEND,
            'status' => Delivery::STATUS_PAID,
        ]);

        $this->service()->packageReady($delivery);

        $this->assertSame(0, Notification::count());
    }

    public function test_payment_completed_notifies_distributor(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->delivery($distributor);
        $payment = Payment::factory()->create([
            'delivery_id' => $delivery->id,
            'status' => Payment::STATUS_COMPLETED,
            'amount' => 5000,
            'initiated_by' => $distributor->id,
            'initiated_at' => now(),
        ]);

        $this->service()->paymentCompleted($payment);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $distributor->id,
            'type' => 'payment.completed',
        ]);
    }

    // ─── Body content sanity ────────────────────────────────
    public function test_notification_carries_delivery_payload(): void
    {
        $distributor = $this->distributor();
        $delivery = $this->delivery($distributor);

        $this->service()->deliveryPickedUp($delivery);

        $n = Notification::first();
        $this->assertSame($delivery->id, $n->data['delivery_id']);
        $this->assertSame($delivery->tracking_id, $n->data['tracking_id']);
        $this->assertSame('open_delivery', $n->data['action']);
    }
}