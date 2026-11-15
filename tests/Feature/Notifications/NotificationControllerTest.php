<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
    }

    protected function user(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create();
    }

    protected function seedNotification(User $user, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'type' => 'payment.completed',
            'title' => 'Test',
            'body' => 'Body',
            'data' => ['delivery_id' => 1],
            'channels' => ['in_app'],
            'status' => Notification::STATUS_SENT,
            'priority' => Notification::PRIORITY_NORMAL,
            'sent_at' => now(),
        ], $overrides));
    }

    // ─── Auth ───────────────────────────────────────────────
    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    // ─── Index ──────────────────────────────────────────────
    public function test_index_returns_only_own_notifications(): void
    {
        $me = $this->user();
        $other = $this->user();
        $this->seedNotification($me);
        $this->seedNotification($other);

        $response = $this->actingAs($me, 'api')
            ->getJson('/api/v1/notifications');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($me->id, $response->json('data.data.0.user_id'));
    }

    public function test_index_unread_only_excludes_read(): void
    {
        $me = $this->user();
        $this->seedNotification($me, ['read_at' => now()]);
        $this->seedNotification($me);

        $response = $this->actingAs($me, 'api')
            ->getJson('/api/v1/notifications?unread_only=true');

        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_index_excludes_dismissed(): void
    {
        $me = $this->user();
        $this->seedNotification($me, ['dismissed_at' => now()]);
        $this->seedNotification($me);

        $response = $this->actingAs($me, 'api')
            ->getJson('/api/v1/notifications');

        $this->assertSame(1, $response->json('data.total'));
    }

    // ─── Mark read ──────────────────────────────────────────
    public function test_mark_read_updates_row(): void
    {
        $me = $this->user();
        $n = $this->seedNotification($me);

        $response = $this->actingAs($me, 'api')
            ->patchJson("/api/v1/notifications/{$n->id}/read");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.read_at', fn ($v) => $v !== null);

        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_mark_read_404s_for_other_users_notification(): void
    {
        $me = $this->user();
        $other = $this->user();
        $n = $this->seedNotification($other);

        $this->actingAs($me, 'api')
            ->patchJson("/api/v1/notifications/{$n->id}/read")
            ->assertStatus(404);
    }

    // ─── Dismiss ────────────────────────────────────────────
    public function test_dismiss_sets_dismissed_at(): void
    {
        $me = $this->user();
        $n = $this->seedNotification($me);

        $this->actingAs($me, 'api')
            ->patchJson("/api/v1/notifications/{$n->id}/dismiss")
            ->assertStatus(200);

        $this->assertNotNull($n->fresh()->dismissed_at);
    }

    // ─── Mark all read ──────────────────────────────────────
    public function test_mark_all_read_returns_count(): void
    {
        $me = $this->user();
        $this->seedNotification($me);
        $this->seedNotification($me);
        $this->seedNotification($me, ['read_at' => now()]);

        $response = $this->actingAs($me, 'api')
            ->patchJson('/api/v1/notifications/read-all');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.marked_count', 2);
    }

    public function test_mark_all_read_is_idempotent(): void
    {
        $me = $this->user();
        $this->seedNotification($me);

        $this->actingAs($me, 'api')
            ->patchJson('/api/v1/notifications/read-all')
            ->assertJsonPath('data.marked_count', 1);

        $this->actingAs($me, 'api')
            ->patchJson('/api/v1/notifications/read-all')
            ->assertJsonPath('data.marked_count', 0);
    }
}