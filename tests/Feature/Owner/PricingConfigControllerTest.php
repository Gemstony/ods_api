<?php

namespace Tests\Feature\Owner;

use App\Models\PricingConfig;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingConfigControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        (new PricingConfigSeeder())->run();
    }

    protected function owner(): User
    {
        return User::factory()->role(Role::OWNER)->create();
    }

    protected function manager(): User
    {
        return User::factory()->role(Role::MANAGER)->create();
    }

    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'local_rate_per_km' => 500,
            'local_minimum_fee' => 1000,
            'local_rounding_increment' => 500,
            'regional_base_fee' => 0,
            'regional_minimum_fee' => 0,
            'regional_rounding_increment' => 500,
            'rider_baseline_percent' => 80,
            'rider_bonus_percent' => 10,
            'dispatcher_compensation_model' => 'flat_per_package',
            'dispatcher_flat_fee' => 500,
            'dispatcher_shift_allowance' => 0,
            'dispatcher_percent' => 0,
            'reward_tier1_threshold' => 20,
            'reward_tier1_discount_percent' => 50,
            'reward_tier2_threshold' => 50,
            'reward_rolling_window_days' => 30,
        ], $overrides);
    }

    // ─── Authorization ────────────────────────────────────────
    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/owner/pricing-config')->assertStatus(401);
        $this->putJson('/api/v1/owner/pricing-config', [])->assertStatus(401);
    }

    public function test_endpoints_reject_non_owner_roles(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/owner/pricing-config')
            ->assertStatus(403);
        $this->actingAs($manager, 'api')
            ->putJson('/api/v1/owner/pricing-config', $this->validPayload())
            ->assertStatus(403);
    }

    // ─── show ─────────────────────────────────────────────────
    public function test_show_returns_singleton_config(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/pricing-config');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.local_rate_per_km', 500)
            ->assertJsonPath('data.local_minimum_fee', 1000)
            ->assertJsonPath('data.rider_baseline_percent', 80)
            ->assertJsonPath('data.rider_bonus_percent', 10)
            ->assertJsonPath('data.reward_tier1_threshold', 20)
            ->assertJsonPath('data.reward_tier2_threshold', 50);
    }

    // ─── update ───────────────────────────────────────────────
    public function test_update_changes_pricing_fields(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/pricing-config', $this->validPayload([
                'local_rate_per_km' => 600,
                'local_minimum_fee' => 1200,
                'rider_baseline_percent' => 75,
                'rider_bonus_percent' => 15,
            ]));

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.local_rate_per_km', 600)
            ->assertJsonPath('data.local_minimum_fee', 1200)
            ->assertJsonPath('data.rider_baseline_percent', 75)
            ->assertJsonPath('data.rider_bonus_percent', 15);

        $config = PricingConfig::current();
        $this->assertSame(600.0, $config->local_rate_per_km);
        $this->assertSame(75.0, $config->rider_baseline_percent);
    }

    public function test_update_validates_required_fields(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/pricing-config', [])
            ->assertStatus(422)
            ->assertJsonStructure([
                'errors' => [
                    'local_rate_per_km', 'local_minimum_fee',
                    'dispatcher_compensation_model',
                    'reward_tier1_threshold',
                ],
            ]);
    }

    public function test_update_rejects_baseline_plus_bonus_over_100(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/pricing-config', $this->validPayload([
                'rider_baseline_percent' => 90,
                'rider_bonus_percent' => 20, // 90 + 20 = 110 > 100
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['rider_bonus_percent']]);
    }

    public function test_update_allows_baseline_plus_bonus_at_exactly_100(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/pricing-config', $this->validPayload([
                'rider_baseline_percent' => 90,
                'rider_bonus_percent' => 10, // 90 + 10 = 100
            ]))
            ->assertStatus(200);
    }

    public function test_update_rejects_tier2_not_greater_than_tier1(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/pricing-config', $this->validPayload([
                'reward_tier1_threshold' => 50,
                'reward_tier2_threshold' => 20, // smaller than tier1
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reward_tier2_threshold']]);
    }

    public function test_update_rejects_invalid_dispatcher_model(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/pricing-config', $this->validPayload([
                'dispatcher_compensation_model' => 'unknown_model',
            ]))
            ->assertStatus(422);
    }

    public function test_update_all_three_dispatcher_models(): void
    {
        $owner = $this->owner();

        foreach (['flat_per_package', 'shift_allowance', 'percent_of_fee'] as $model) {
            $this->actingAs($owner, 'api')
                ->putJson('/api/v1/owner/pricing-config', $this->validPayload([
                    'dispatcher_compensation_model' => $model,
                ]))
                ->assertStatus(200)
                ->assertJsonPath('data.dispatcher_compensation_model', $model);
        }
    }

    public function test_update_rejects_negative_rates(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/pricing-config', $this->validPayload([
                'local_rate_per_km' => -100,
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['local_rate_per_km']]);
    }

    public function test_update_rejects_percentages_over_100(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/pricing-config', $this->validPayload([
                'rider_baseline_percent' => 150,
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['rider_baseline_percent']]);
    }

    public function test_update_records_updated_by(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/pricing-config', $this->validPayload())
            ->assertStatus(200);

        $this->assertSame($owner->id, PricingConfig::current()->updated_by);
    }
}