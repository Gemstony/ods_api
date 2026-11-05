<?php

namespace Tests\Feature\Rider;

use App\Models\Delivery;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
    }

    protected function rider(): User
    {
        return User::factory()->role(Role::RIDER)->create();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/rider/dashboard')->assertStatus(401);
    }

    public function test_rejects_other_roles(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/rider/dashboard')
            ->assertStatus(403);
    }

    public function test_returns_zero_counts_for_new_rider(): void
    {
        $rider = $this->rider();

        $response = $this->actingAs($rider, 'api')
            ->getJson('/api/v1/rider/dashboard');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.tasks.available', 0)
            ->assertJsonPath('data.tasks.active', 0)
            ->assertJsonPath('data.tasks.delivered_today', 0)
            ->assertJsonPath('data.tasks.total_delivered', 0)
            ->assertJsonStructure([
                'data' => [
                    'tasks' => [
                        'available',
                        'active',
                        'delivered_today',
                        'delivered_this_month',
                        'total_delivered',
                    ],
                    'earnings' => ['this_month_tsh', 'pending_tsh'],
                    'generated_at',
                ],
            ]);
    }

    public function test_counts_assigned_and_active_correctly(): void
    {
        $rider = $this->rider();

        Delivery::factory()->create([
            'rider_id' => $rider->id,
            'status' => Delivery::STATUS_ASSIGNED,
        ]);
        Delivery::factory()->create([
            'rider_id' => $rider->id,
            'status' => Delivery::STATUS_ASSIGNED,
        ]);
        Delivery::factory()->create([
            'rider_id' => $rider->id,
            'status' => Delivery::STATUS_ACCEPTED,
        ]);
        Delivery::factory()->create([
            'rider_id' => $rider->id,
            'status' => Delivery::STATUS_PICKED_UP,
        ]);
        // Other rider's delivery — must not be counted
        Delivery::factory()->create([
            'rider_id' => $this->rider()->id,
            'status' => Delivery::STATUS_ASSIGNED,
        ]);

        $response = $this->actingAs($rider, 'api')
            ->getJson('/api/v1/rider/dashboard');

        $response
            ->assertJsonPath('data.tasks.available', 2)
            ->assertJsonPath('data.tasks.active', 2);
    }

    public function test_counts_delivered_today(): void
    {
        $rider = $this->rider();

        Delivery::factory()->create([
            'rider_id' => $rider->id,
            'status' => Delivery::STATUS_DELIVERED,
            'delivered_at' => now()->subMinutes(30),
        ]);
        Delivery::factory()->create([
            'rider_id' => $rider->id,
            'status' => Delivery::STATUS_DELIVERED,
            'delivered_at' => now()->subDays(3),
        ]);

        $response = $this->actingAs($rider, 'api')
            ->getJson('/api/v1/rider/dashboard');

        $response
            ->assertJsonPath('data.tasks.delivered_today', 1)
            ->assertJsonPath('data.tasks.total_delivered', 2);
    }
}