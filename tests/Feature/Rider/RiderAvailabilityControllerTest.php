<?php

namespace Tests\Feature\Rider;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderAvailabilityControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder)->run();
        (new PricingConfigSeeder)->run();
        (new SettingsSeeder)->run();
    }

    protected function branch(string $code = 'KRK-01'): Branch
    {
        return Branch::factory()->create(['code' => $code]);
    }

    protected function rider(Branch $branch): User
    {
        return User::factory()->role(Role::RIDER)->create([
            'branch_id' => $branch->id,
            'is_available' => true,
            'available_since' => now()->subMinutes(5),
        ]);
    }

    // ─── Auth ───────────────────────────────────────────────
    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/v1/rider/queue-status')->assertStatus(401);
    }

    public function test_show_rejects_non_rider(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/rider/queue-status')
            ->assertStatus(403);
    }

    public function test_show_403_when_rider_has_no_branch(): void
    {
        $rider = User::factory()->role(Role::RIDER)->create(['branch_id' => null]);
        $this->actingAs($rider, 'api')
            ->getJson('/api/v1/rider/queue-status')
            ->assertStatus(403);
    }

    // ─── Queue status ───────────────────────────────────────
    public function test_show_returns_available_with_position(): void
    {
        $branch = $this->branch();
        // Rider ahead
        User::factory()->role(Role::RIDER)->create([
            'branch_id' => $branch->id,
            'is_available' => true,
            'available_since' => now()->subMinutes(10),
        ]);
        $me = $this->rider($branch);

        $response = $this->actingAs($me, 'api')
            ->getJson('/api/v1/rider/queue-status');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.queue_position', 2)
            ->assertJsonPath('data.queue_depth', 2)
            ->assertJsonPath('data.current_delivery', null);
    }

    public function test_show_returns_unavailable_position_null(): void
    {
        $branch = $this->branch();
        $me = $this->rider($branch);
        $me->markBusy();

        $response = $this->actingAs($me->fresh(), 'api')
            ->getJson('/api/v1/rider/queue-status');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.queue_position', null);
    }

    public function test_show_includes_current_delivery(): void
    {
        $branch = $this->branch();
        $me = $this->rider($branch);
        Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_ASSIGNED,
            'rider_id' => $me->id,
        ]);

        $response = $this->actingAs($me, 'api')
            ->getJson('/api/v1/rider/queue-status');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.current_delivery.status', 'assigned');
        $this->assertNotNull($response->json('data.current_delivery.tracking_id'));
    }

    // ─── Toggle availability ────────────────────────────────
    public function test_toggle_unavailable_marks_rider_busy(): void
    {
        $branch = $this->branch();
        $me = $this->rider($branch);

        $response = $this->actingAs($me, 'api')
            ->patchJson('/api/v1/rider/availability', ['available' => false]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.is_available', false);

        $this->assertFalse($me->fresh()->is_available);
    }

    public function test_toggle_available_marks_rider_available_with_fresh_timestamp(): void
    {
        $branch = $this->branch();
        $me = $this->rider($branch);
        $me->markBusy();

        $before = now()->subSecond();
        $response = $this->actingAs($me->fresh(), 'api')
            ->patchJson('/api/v1/rider/availability', ['available' => true]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.is_available', true);

        $fresh = $me->fresh();
        $this->assertTrue($fresh->is_available);
        $this->assertNotNull($fresh->available_since);
        $this->assertTrue($fresh->available_since->gte($before));
    }

    public function test_toggle_available_is_idempotent_and_preserves_queue_position(): void
    {
        $branch = $this->branch();
        $me = $this->rider($branch);
        $originalSince = $me->available_since;

        $this->actingAs($me, 'api')
            ->patchJson('/api/v1/rider/availability', ['available' => true])
            ->assertStatus(200);

        $this->assertEquals(
            $originalSince?->toIso8601String(),
            $me->fresh()->available_since?->toIso8601String(),
        );
    }

    public function test_toggle_unavailable_blocked_when_carrying_a_delivery(): void
    {
        $branch = $this->branch();
        $me = $this->rider($branch);
        Delivery::factory()->create([
            'pickup_branch_id' => $branch->id,
            'service_type' => Delivery::SERVICE_RECEIVE,
            'status' => Delivery::STATUS_ASSIGNED,
            'rider_id' => $me->id,
        ]);

        $this->actingAs($me, 'api')
            ->patchJson('/api/v1/rider/availability', ['available' => false])
            ->assertStatus(409);

        $this->assertTrue($me->fresh()->is_available);
    }

    public function test_toggle_validates_available_field(): void
    {
        $branch = $this->branch();
        $me = $this->rider($branch);

        $this->actingAs($me, 'api')
            ->patchJson('/api/v1/rider/availability', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['available']]);
    }
}
