<?php

namespace Tests\Feature\Rider;

use App\Models\Earning;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderEarningsControllerTest extends TestCase
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
        $this->getJson('/api/v1/rider/earnings')->assertStatus(401);
    }

    public function test_rejects_other_roles(): void
    {
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->create();
        $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/rider/earnings')
            ->assertStatus(403);
    }

    public function test_returns_empty_list_for_new_rider(): void
    {
        $rider = $this->rider();

        $response = $this->actingAs($rider, 'api')
            ->getJson('/api/v1/rider/earnings');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.summary.pending_tsh', 0);

        $this->assertCount(0, $response->json('data.data'));
    }

    public function test_returns_only_own_earnings(): void
    {
        $rider = $this->rider();
        $other = $this->rider();

        Earning::factory()->create([
            'user_id' => $rider->id,
            'amount' => 5000,
            'status' => Earning::STATUS_PENDING,
            'type' => Earning::TYPE_RIDER_BASELINE,
        ]);
        Earning::factory()->create([
            'user_id' => $other->id,
            'amount' => 9999,
            'status' => Earning::STATUS_PENDING,
            'type' => Earning::TYPE_RIDER_BASELINE,
        ]);

        $response = $this->actingAs($rider, 'api')
            ->getJson('/api/v1/rider/earnings');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame(5000.0, (float) $response->json('data.data.0.amount'));
        $this->assertSame(5000.0, (float) $response->json('data.summary.pending_tsh'));
    }
}