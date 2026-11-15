<?php

namespace Tests\Feature\Sms;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Services\Sms\BeemSmsClient;
use App\Services\Sms\BeemSmsException;
use App\Services\Sms\SmsDispatcher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        config([
            'beem.sender_id' => 'ODSTZ',
            'beem.rate_limit_per_second' => 100, // don't slow tests
        ]);
    }

    protected function user(string $phone = '255712345678'): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create(['phone' => $phone]);
    }

    protected function pendingNotification(User $user, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'type' => 'payment.completed',
            'title' => 'Test',
            'body' => 'Test body',
            'data' => ['delivery_id' => 1],
            'channels' => ['in_app', 'sms'],
            'status' => Notification::STATUS_PENDING,
            'priority' => Notification::PRIORITY_NORMAL,
        ], $overrides));
    }

    // ─── Sender ID guard ────────────────────────────────────
    public function test_skips_when_sender_id_is_info(): void
    {
        config(['beem.sender_id' => 'INFO']);
        $this->pendingNotification($this->user());

        $result = app(SmsDispatcher::class)->dispatchPending();

        $this->assertSame(0, $result['dispatched']);
        $this->assertNotNull($result['reason']);
        // Notification stays pending.
        $this->assertSame(
            Notification::STATUS_PENDING,
            Notification::first()->status,
        );
    }

    public function test_skips_when_sender_id_is_empty(): void
    {
        config(['beem.sender_id' => '']);
        $this->pendingNotification($this->user());

        $result = app(SmsDispatcher::class)->dispatchPending();

        $this->assertSame(0, $result['dispatched']);
        $this->assertNotNull($result['reason']);
    }

    // ─── Happy path ─────────────────────────────────────────
    public function test_dispatches_pending_sms_channel_notifications(): void
    {
        $user = $this->user();
        $notification = $this->pendingNotification($user);

        $fake = $this->createMock(BeemSmsClient::class);
        $fake->method('send')->willReturn([
            'beem_request_id' => '42',
            'beem_code' => 100,
            'response' => ['code' => 100, 'request_id' => 42],
        ]);
        $this->app->instance(BeemSmsClient::class, $fake);

        $result = app(SmsDispatcher::class)->dispatchPending();

        $this->assertSame(1, $result['dispatched']);
        $this->assertSame(0, $result['failed']);

        $fresh = $notification->fresh();
        $this->assertSame(Notification::STATUS_SENT, $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame('42', $fresh->data['sms_beem_request_id']);
    }

    public function test_uses_sms_message_override_when_present(): void
    {
        $user = $this->user();
        $this->pendingNotification($user, [
            'data' => [
                'delivery_id' => 1,
                'sms_message' => 'CUSTOM: code is 123456',
            ],
        ]);

        $captured = null;
        $fake = $this->createMock(BeemSmsClient::class);
        $fake->method('send')->willReturnCallback(function (
            $dest, $msg, $nid, $triggeredBy
        ) use (&$captured) {
            $captured = $msg;
            return ['beem_request_id' => '1', 'beem_code' => 100, 'response' => []];
        });
        $this->app->instance(BeemSmsClient::class, $fake);

        app(SmsDispatcher::class)->dispatchPending();

        $this->assertSame('CUSTOM: code is 123456', $captured);
    }

    // ─── Failure paths ──────────────────────────────────────
    public function test_marks_failed_when_user_has_no_phone(): void
    {
        $user = User::factory()->role(Role::DISTRIBUTOR)->create(['phone' => '']);
        $n = $this->pendingNotification($user);

        $result = app(SmsDispatcher::class)->dispatchPending();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(Notification::STATUS_FAILED, $n->fresh()->status);
    }

    public function test_marks_failed_on_beem_error(): void
    {
        $this->pendingNotification($this->user());

        $fake = $this->createMock(BeemSmsClient::class);
        $fake->method('send')->willThrowException(
            new BeemSmsException('Invalid phone', beemCode: 101),
        );
        $this->app->instance(BeemSmsClient::class, $fake);

        $result = app(SmsDispatcher::class)->dispatchPending();

        $this->assertSame(1, $result['failed']);
        $failed = Notification::first();
        $this->assertSame(Notification::STATUS_FAILED, $failed->status);
        $this->assertSame(101, $failed->data['sms_beem_code']);
    }

    // ─── Filtering ──────────────────────────────────────────
    public function test_skips_notifications_without_sms_channel(): void
    {
        $user = $this->user();
        $this->pendingNotification($user, ['channels' => ['in_app']]);

        $fake = $this->createMock(BeemSmsClient::class);
        $fake->expects($this->never())->method('send');
        $this->app->instance(BeemSmsClient::class, $fake);

        $result = app(SmsDispatcher::class)->dispatchPending();

        $this->assertSame(0, $result['dispatched']);
        $this->assertSame(0, $result['failed']);
    }

    public function test_skips_already_sent_notifications(): void
    {
        $user = $this->user();
        $this->pendingNotification($user, [
            'status' => Notification::STATUS_SENT,
            'sent_at' => now()->subMinutes(10),
        ]);

        $fake = $this->createMock(BeemSmsClient::class);
        $fake->expects($this->never())->method('send');
        $this->app->instance(BeemSmsClient::class, $fake);

        $result = app(SmsDispatcher::class)->dispatchPending();

        $this->assertSame(0, $result['dispatched']);
    }

    // ─── Dry run ────────────────────────────────────────────
    public function test_dry_run_does_not_call_beem(): void
    {
        $this->pendingNotification($this->user());

        $fake = $this->createMock(BeemSmsClient::class);
        $fake->expects($this->never())->method('send');
        $this->app->instance(BeemSmsClient::class, $fake);

        $result = app(SmsDispatcher::class)->dispatchPending(dryRun: true);

        $this->assertSame(1, $result['dispatched']);
        $this->assertSame(
            Notification::STATUS_PENDING,
            Notification::first()->status,
        );
    }
}