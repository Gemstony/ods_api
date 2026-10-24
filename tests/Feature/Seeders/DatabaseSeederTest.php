<?php

namespace Tests\Feature\Seeders;

use App\Models\Permission;
use App\Models\PricingConfig;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PricingConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed the database directly by calling each seeder's run() method.
     *
     * Why not app(DatabaseSeeder::class)->run()? Under Laravel 12 +
     * RefreshDatabase + :memory: SQLite, the Seeder::call() mechanism can
     * complete without error while silently not writing to the test's
     * connection. Direct invocation eliminates that ambiguity — every
     * insert happens on the exact same connection the test asserts against.
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder())->run();
        (new PermissionSeeder())->run();
        (new RolePermissionSeeder())->run();
        (new OwnerSeeder())->run();
        (new PricingConfigSeeder())->run();
        (new SettingsSeeder())->run();
    }

    // ─── Roles ─────────────────────────────────────────────────
    public function test_all_five_roles_are_seeded(): void
    {
        foreach ([
            Role::OWNER,
            Role::MANAGER,
            Role::DISTRIBUTOR,
            Role::RIDER,
            Role::RECEIVER,
        ] as $roleName) {
            $this->assertDatabaseHas('roles', ['name' => $roleName]);
        }

        $this->assertSame(5, Role::count());
    }

    public function test_role_display_names_are_human_readable(): void
    {
        $this->assertSame('Owner', Role::where('name', Role::OWNER)->value('display_name'));
        $this->assertSame('Delivery Agent', Role::where('name', Role::RIDER)->value('display_name'));
    }

    // ─── Permissions ───────────────────────────────────────────
    public function test_all_twelve_permissions_are_seeded(): void
    {
        $this->assertSame(12, Permission::count());

        foreach ([
            'manage_users',
            'manage_branches',
            'manage_settings',
            'manage_finance',
            'view_audit_logs',
            'resolve_disputes',
            'manage_staff',
            'view_branch_operations',
            'handle_packages',
            'verify_shipments',
            'create_delivery_requests',
            'accept_deliveries',
        ] as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name]);
        }
    }

    // ─── Role-permission mapping ───────────────────────────────
    public function test_owner_has_expected_permissions(): void
    {
        $owner = User::where('email', config('owner.email'))->firstOrFail();
        $permissions = $owner->role->permissions->pluck('name')->sort()->values()->all();

        $this->assertSame([
            'manage_branches',
            'manage_finance',
            'manage_settings',
            'manage_users',
            'resolve_disputes',
            'view_audit_logs',
        ], $permissions);
    }

    public function test_manager_has_expected_permissions(): void
    {
        $manager = Role::where('name', Role::MANAGER)->firstOrFail();
        $permissions = $manager->permissions->pluck('name')->sort()->values()->all();

        $this->assertSame([
            'manage_staff',
            'resolve_disputes',
            'view_branch_operations',
        ], $permissions);
    }

    public function test_receiver_has_expected_permissions(): void
    {
        $receiver = Role::where('name', Role::RECEIVER)->firstOrFail();
        $permissions = $receiver->permissions->pluck('name')->sort()->values()->all();

        $this->assertSame(['handle_packages', 'verify_shipments'], $permissions);
    }

    // ─── Owner ─────────────────────────────────────────────────
    public function test_owner_is_seeded_with_correct_attributes(): void
    {
        $owner = User::where('email', config('owner.email'))->firstOrFail();
        $this->assertSame(Role::OWNER, $owner->role->name);
        $this->assertSame(User::STATUS_ACTIVE, $owner->status);
        $this->assertSame(config('owner.name'), $owner->name);
        $this->assertSame(config('owner.phone'), $owner->phone);
    }

    // ─── Pricing config ────────────────────────────────────────
    public function test_pricing_config_singleton_is_seeded(): void
    {
        $this->assertSame(1, PricingConfig::count());
        $config = PricingConfig::current();
        $this->assertSame(500.00, $config->local_rate_per_km);
        $this->assertSame(1000.00, $config->local_minimum_fee);
        $this->assertSame(500, $config->local_rounding_increment);
        $this->assertSame(80.00, $config->rider_baseline_percent);
        $this->assertSame(10.00, $config->rider_bonus_percent);
    }

    // ─── Settings ──────────────────────────────────────────────
    public function test_default_settings_are_seeded(): void
    {
        $this->assertSame(20, Setting::get('batching.window_minutes'));
        $this->assertEquals(5.0, Setting::get('location.pickup_radius_km'));
        $this->assertSame(true, Setting::get('security.verification_required_delivery'));
        $this->assertSame(['clickpesa'], Setting::get('payments.enabled_methods'));
        $this->assertSame('0.1.0', Setting::get('app.min_supported_version'));
        $this->assertSame(false, Setting::get('app.maintenance_mode'));
    }

    public function test_app_settings_are_public(): void
    {
        $publicKeys = Setting::where('is_public', true)->pluck('key')->all();

        $this->assertContains('app.min_supported_version', $publicKeys);
        $this->assertContains('app.maintenance_mode', $publicKeys);
        $this->assertContains('app.app_name', $publicKeys);
        $this->assertNotContains('batching.window_minutes', $publicKeys);
        $this->assertNotContains('security.verification_required_delivery', $publicKeys);
    }

    // ─── Idempotency ───────────────────────────────────────────
    public function test_reseeding_is_idempotent(): void
    {
        $firstCounts = [
            'roles' => Role::count(),
            'permissions' => Permission::count(),
            'users' => User::count(),
            'pricing_config' => PricingConfig::count(),
            'settings' => Setting::count(),
        ];

        // Re-run every seeder.
        (new RoleSeeder())->run();
        (new PermissionSeeder())->run();
        (new RolePermissionSeeder())->run();
        (new OwnerSeeder())->run();
        (new PricingConfigSeeder())->run();
        (new SettingsSeeder())->run();

        $this->assertSame($firstCounts['roles'], Role::count());
        $this->assertSame($firstCounts['permissions'], Permission::count());
        $this->assertSame($firstCounts['users'], User::count());
        $this->assertSame($firstCounts['pricing_config'], PricingConfig::count());
        $this->assertSame($firstCounts['settings'], Setting::count());
    }

    // ─── End-to-end: seeded Owner can log in via HTTP ──────────
    public function test_seeded_owner_can_log_in_via_http(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => config('owner.email'),
            'password' => config('owner.password'),
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.role', Role::OWNER)
            ->assertJsonPath('data.user.status', User::STATUS_ACTIVE)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'role', 'permissions'],
                    'access_token',
                    'refresh_token',
                ],
            ]);

        $permissions = $response->json('data.user.permissions');
        $this->assertContains('manage_users', $permissions);
        $this->assertContains('manage_branches', $permissions);
    }

    public function test_seeded_owner_can_access_me_endpoint(): void
    {
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => config('owner.email'),
            'password' => config('owner.password'),
        ]);

        $accessToken = $loginResponse->json('data.access_token');

        $this->withHeader('Authorization', "Bearer {$accessToken}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.role', Role::OWNER)
            ->assertJsonPath('data.email', config('owner.email'));
    }
}