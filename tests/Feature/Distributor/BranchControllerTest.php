<?php

namespace Tests\Feature\Distributor;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
    }

    protected function distributor(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/distributor/branches')->assertStatus(401);
    }

    public function test_rejects_other_roles(): void
    {
        foreach ([Role::OWNER, Role::MANAGER, Role::RIDER, Role::RECEIVER] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user, 'api')
                ->getJson('/api/v1/distributor/branches')
                ->assertStatus(403);
        }
    }

    public function test_returns_active_branches_only(): void
    {
        $distributor = $this->distributor();

        Branch::factory()->create([
            'name' => 'Alpha Branch',
            'status' => Branch::STATUS_ACTIVE,
        ]);
        Branch::factory()->create([
            'name' => 'Beta Branch',
            'status' => Branch::STATUS_ACTIVE,
        ]);
        Branch::factory()->create([
            'name' => 'Inactive Branch',
            'status' => Branch::STATUS_INACTIVE,
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/branches');

        $response->assertStatus(200);
        $names = array_column($response->json('data.data'), 'name');
        $this->assertContains('Alpha Branch', $names);
        $this->assertContains('Beta Branch', $names);
        $this->assertNotContains('Inactive Branch', $names);
        $this->assertCount(2, $names);
    }

    public function test_orders_by_name(): void
    {
        $distributor = $this->distributor();
        Branch::factory()->create(['name' => 'Zeta Branch', 'status' => 'active']);
        Branch::factory()->create(['name' => 'Alpha Branch', 'status' => 'active']);
        Branch::factory()->create(['name' => 'Mu Branch', 'status' => 'active']);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/branches');

        $names = array_column($response->json('data.data'), 'name');
        $this->assertSame(['Alpha Branch', 'Mu Branch', 'Zeta Branch'], $names);
    }

    public function test_returns_empty_list_when_no_active_branches(): void
    {
        $distributor = $this->distributor();

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/branches');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.data', []);
    }

    public function test_includes_coordinates_and_operating_hours(): void
    {
        $distributor = $this->distributor();
        Branch::factory()->create([
            'status' => 'active',
            'latitude' => -6.79,
            'longitude' => 39.20,
            'operating_hours' => ['monday' => ['open' => '08:00', 'close' => '18:00']],
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/distributor/branches');

        $response->assertJsonPath('data.data.0.latitude', -6.79);
        $response->assertJsonPath('data.data.0.longitude', 39.20);
        $this->assertNotNull($response->json('data.data.0.operating_hours'));
    }
}