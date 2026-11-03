<?php

namespace Tests\Feature\Distributor;

use App\Models\PricingConfig;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RewardsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
    }

    protected function distributor(): User
    {
        return User::factory()->role(Role::DISTRIBUTOR)->create();
    }

    // ─── Auth ─────────────────────────────────────────────────
    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/rewards/me')->assertStatus(401);
    }

    public function test_rejects_non_distributor_roles(): void
    {
        foreach ([Role::OWNER, Role::MANAGER, Role::RIDER, Role::RECEIVER] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user, 'api')
                ->getJson('/api/v1/rewards/me')
                ->assertStatus(403);
        }
    }

    // ─── Empty progress ───────────────────────────────────────
    public function test_creates_default_reward_when_missing(): void
    {
        $distributor = $this->distributor();

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/rewards/me');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.progress.monthly_count', 0)
            ->assertJsonPath('data.progress.rolling_count', 0)
            ->assertJsonPath('data.progress.tier1_available', false)
            ->assertJsonPath('data.progress.tier2_available', false)
            ->assertJsonStructure([
                'data' => [
                    'progress' => [
                        'id', 'distributor_id', 'current_month',
                        'monthly_count', 'rolling_count',
                        'tier1_available', 'tier2_available',
                    ],
                    'thresholds' => [
                        'tier1', 'tier2', 'tier1_discount_percent',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('rewards', [
            'distributor_id' => $distributor->id,
            'monthly_count'  => 0,
        ]);
    }

    // ─── Existing progress ────────────────────────────────────
    public function test_returns_existing_progress(): void
    {
        $distributor = $this->distributor();

        \App\Models\Reward::for($distributor)->update([
            'monthly_count' => 8,
            'rolling_count' => 30,
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/rewards/me');

        $response
            ->assertJsonPath('data.progress.monthly_count', 8)
            ->assertJsonPath('data.progress.rolling_count', 30);
    }

    // ─── Thresholds come from current config ──────────────────
    public function test_thresholds_reflect_current_pricing_config(): void
    {
        $distributor = $this->distributor();

        PricingConfig::current()->update([
            'reward_tier1_threshold'         => 15,
            'reward_tier2_threshold'         => 40,
            'reward_tier1_discount_percent'  => 60,
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/rewards/me');

        $response
            ->assertJsonPath('data.thresholds.tier1', 15)
            ->assertJsonPath('data.thresholds.tier2', 40)
            ->assertJsonPath('data.thresholds.tier1_discount_percent', 60);
    }

    // ─── Available reward state ───────────────────────────────
    public function test_returns_available_tier1_when_reached(): void
    {
        $distributor = $this->distributor();

        \App\Models\Reward::for($distributor)->update([
            'monthly_count'    => 20,
            'tier1_available'  => true,
            'tier1_reached_at' => now(),
            'tier1_expires_at' => now()->addDays(30),
        ]);

        $response = $this->actingAs($distributor, 'api')
            ->getJson('/api/v1/rewards/me');

        $response
            ->assertJsonPath('data.progress.monthly_count', 20)
            ->assertJsonPath('data.progress.tier1_available', true);
        $this->assertNotNull($response->json('data.progress.tier1_expires_at'));
    }

    // ─── Scoped to caller ─────────────────────────────────────
    public function test_each_distributor_gets_their_own_reward(): void
    {
        $d1 = $this->distributor();
        $d2 = $this->distributor();

        \App\Models\Reward::for($d1)->update(['monthly_count' => 5]);
        \App\Models\Reward::for($d2)->update(['monthly_count' => 15]);

        $r1 = $this->actingAs($d1, 'api')->getJson('/api/v1/rewards/me');
        $r2 = $this->actingAs($d2, 'api')->getJson('/api/v1/rewards/me');

        $r1->assertJsonPath('data.progress.monthly_count', 5);
        $r2->assertJsonPath('data.progress.monthly_count', 15);
    }
}