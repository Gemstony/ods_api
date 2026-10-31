<?php

namespace Tests\Feature\Owner;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
        // Seed defaults so tests have something to update.
        (new \Database\Seeders\SettingsSeeder())->run();
    }

    protected function owner(): User
    {
        return User::factory()->role(Role::OWNER)->create();
    }

    protected function manager(): User
    {
        return User::factory()->role(Role::MANAGER)->create();
    }

    // ─── Authorization ────────────────────────────────────────
    public function test_all_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/owner/settings')->assertStatus(401);
        $this->getJson('/api/v1/owner/settings/batching.window_minutes')->assertStatus(401);
        $this->putJson('/api/v1/owner/settings/batching.window_minutes', ['value' => 30])->assertStatus(401);
        $this->putJson('/api/v1/owner/settings', ['settings' => []])->assertStatus(401);
    }

    public function test_all_endpoints_reject_non_owner_roles(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/owner/settings')->assertStatus(403);
        $this->actingAs($manager, 'api')
            ->putJson('/api/v1/owner/settings/batching.window_minutes', ['value' => 30])
            ->assertStatus(403);
    }

    // ─── index ────────────────────────────────────────────────
    public function test_index_returns_settings_grouped_by_category(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/settings');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'batching' => [
                        '*' => ['key', 'value', 'category', 'description', 'is_public'],
                    ],
                    'location',
                    'finance',
                    'payments',
                    'security',
                    'notifications',
                    'app',
                ],
            ]);
    }

    public function test_index_returns_all_seeded_settings(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/settings');

        $total = 0;
        foreach ($response->json('data') as $settings) {
            $total += count($settings);
        }
        $this->assertGreaterThanOrEqual(21, $total);
    }

    // ─── show ─────────────────────────────────────────────────
    public function test_show_returns_a_single_setting(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/settings/batching.window_minutes');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.key', 'batching.window_minutes')
            ->assertJsonPath('data.value', 20)
            ->assertJsonPath('data.category', 'batching');
    }

    public function test_show_returns_404_for_unknown_key(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/settings/does.not.exist')
            ->assertStatus(404);
    }

    // ─── update (single) ──────────────────────────────────────
    public function test_update_changes_setting_value(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings/batching.window_minutes', [
                'value' => 30,
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.key', 'batching.window_minutes')
            ->assertJsonPath('data.value', 30);

        $this->assertSame(30, Setting::get('batching.window_minutes'));
        $this->assertSame($owner->id, Setting::where('key', 'batching.window_minutes')->first()->updated_by);
    }

    public function test_update_validates_against_schema(): void
    {
        $owner = $this->owner();

        // batching.window_minutes must be integer 1–120
        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings/batching.window_minutes', [
                'value' => 0,
            ])
            ->assertStatus(422);

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings/batching.window_minutes', [
                'value' => 'not-a-number',
            ])
            ->assertStatus(422);
    }

    public function test_update_rejects_unknown_key(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings/not.a.real.key', [
                'value' => 'x',
            ])
            ->assertStatus(422);
    }

    public function test_update_boolean_setting(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings/security.verification_required_delivery', [
                'value' => false,
            ]);

        $response->assertStatus(200)->assertJsonPath('data.value', false);
        $this->assertFalse(Setting::get('security.verification_required_delivery'));
    }

    public function test_update_array_setting(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings/payments.enabled_methods', [
                'value' => ['clickpesa', 'cash'],
            ]);

        $response->assertStatus(200);
        $this->assertSame(
            ['clickpesa', 'cash'],
            Setting::get('payments.enabled_methods'),
        );
    }

    public function test_update_rejects_invalid_array_item(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings/payments.enabled_methods', [
                'value' => ['clickpesa', 'bitcoin'],
            ])
            ->assertStatus(422);
    }

    public function test_update_does_not_change_is_public(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings/app.app_name', [
                'value' => 'New App Name',
            ])
            ->assertStatus(200);

        $setting = Setting::where('key', 'app.app_name')->first();
        // is_public was true from seeding, stays true.
        $this->assertTrue($setting->is_public);
    }

    // ─── bulk update ──────────────────────────────────────────
    public function test_bulk_update_applies_multiple_changes(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings', [
                'settings' => [
                    ['key' => 'batching.window_minutes', 'value' => 25],
                    ['key' => 'location.pickup_radius_km', 'value' => 6.5],
                    ['key' => 'security.verification_required_pickup', 'value' => false],
                ],
            ]);

        $response->assertStatus(200);

        $this->assertSame(25, Setting::get('batching.window_minutes'));
        $this->assertSame(6.5, Setting::get('location.pickup_radius_km'));
        $this->assertFalse(Setting::get('security.verification_required_pickup'));
    }

    public function test_bulk_update_is_atomic(): void
    {
        $owner = $this->owner();
        $originalWindow = Setting::get('batching.window_minutes');

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings', [
                'settings' => [
                    ['key' => 'batching.window_minutes', 'value' => 25],
                    ['key' => 'location.pickup_radius_km', 'value' => 'not-a-number'],
                ],
            ])
            ->assertStatus(422);

        // First update should NOT have been applied — atomicity.
        $this->assertSame($originalWindow, Setting::get('batching.window_minutes'));
    }

    public function test_bulk_update_rejects_unknown_key(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings', [
                'settings' => [
                    ['key' => 'batching.window_minutes', 'value' => 25],
                    ['key' => 'totally.fake.key', 'value' => 10],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_bulk_update_rejects_duplicate_keys(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings', [
                'settings' => [
                    ['key' => 'batching.window_minutes', 'value' => 25],
                    ['key' => 'batching.window_minutes', 'value' => 30],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_bulk_update_rejects_missing_key_in_db(): void
    {
        $owner = $this->owner();

        // Delete a setting from the DB, then try to update it via bulk.
        Setting::where('key', 'batching.window_minutes')->delete();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings', [
                'settings' => [
                    ['key' => 'batching.window_minutes', 'value' => 25],
                ],
            ])
            ->assertStatus(404);
    }

    public function test_bulk_update_rejects_empty_list(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/settings', ['settings' => []])
            ->assertStatus(422);
    }
}