<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use App\Models\Branch;

/**
 * Schema tests for Sub-phase 2.1 — verify every table, column, relation,
 * and JWT claim exists exactly as designed. Cheap to run; catches silent
 * schema drift before it breaks Phase 3.
 */
class AuthSchemaTest extends TestCase
{
    use RefreshDatabase;

    // ─── Tables exist ───────────────────────────────────────────
    public function test_auth_core_tables_exist(): void
    {
        foreach (['roles', 'permissions', 'role_permissions', 'users', 'refresh_tokens'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: $table");
        }
    }

    // ─── Users table has every expected column ──────────────────
    public function test_users_table_has_all_expected_columns(): void
    {
        $columns = Schema::getColumnListing('users');
        $expected = [
            'id', 'role_id', 'name', 'email', 'phone', 'email_verified_at',
            'password', 'status', 'branch_id', 'distributor_id', 'nida',
            'sex', 'date_of_birth', 'vehicle_type', 'vehicle_color',
            'vehicle_ownership', 'plate_number', 'dispatcher_enabled',
            'remember_token', 'created_at', 'updated_at', 'deleted_at',
        ];
        foreach ($expected as $col) {
            $this->assertContains($col, $columns, "Missing users column: $col");
        }
    }

    // ─── Roles ──────────────────────────────────────────────────
    public function test_role_has_users_and_permissions_relations(): void
    {
        $role = Role::create(['name' => Role::OWNER, 'display_name' => 'Owner']);
        $perm = Permission::create(['name' => 'manage_users', 'description' => 'Manage users']);
        $role->permissions()->attach($perm);

        $user = User::factory()->role(Role::OWNER)->create();

        $this->assertTrue($role->users->contains($user));
        $this->assertTrue($role->permissions->contains($perm));
    }

    // ─── User helpers ───────────────────────────────────────────
    public function test_user_role_and_status_helpers_work(): void
    {
        $user = User::factory()->role(Role::RIDER)->create();

        $this->assertTrue($user->hasRole(Role::RIDER));
        $this->assertFalse($user->hasRole(Role::OWNER));
        $this->assertTrue($user->isActive());
    }

    public function test_has_permission_reads_through_role(): void
    {
        $role = Role::create(['name' => Role::MANAGER, 'display_name' => 'Manager']);
        $perm = Permission::create(['name' => 'manage_staff']);
        $role->permissions()->attach($perm);

        $user = User::factory()->role(Role::MANAGER)->create();
        $this->assertTrue($user->hasPermission('manage_staff'));
        $this->assertFalse($user->hasPermission('manage_pricing'));
    }

    // ─── JWT claims ─────────────────────────────────────────────
       // ─── JWT claims ─────────────────────────────────────────────
    public function test_jwt_custom_claims_include_role_branch_status(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->role(Role::RIDER)->atBranch($branch)->create();

        $claims = $user->getJWTCustomClaims();

        $this->assertSame(Role::RIDER, $claims['role']);
        $this->assertSame($branch->id, $claims['branch_id']);
        $this->assertSame(User::STATUS_ACTIVE, $claims['status']);
    }

    // ─── Refresh tokens ─────────────────────────────────────────
    public function test_refresh_token_lifecycle_helpers(): void
    {
        $user = User::factory()->create();
        $token = RefreshToken::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', 'raw-token'),
            'expires_at' => now()->addDays(14),
        ]);

        $this->assertTrue($token->isUsable());
        $this->assertFalse($token->isExpired());
        $this->assertFalse($token->isRevoked());

        $token->update(['revoked_at' => now()]);
        $this->assertFalse($token->fresh()->isUsable());
        $this->assertTrue($token->fresh()->isRevoked());
    }

    public function test_user_cascade_deletes_refresh_tokens(): void
    {
        $user = User::factory()->create();
        RefreshToken::create([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', 'tok'),
            'expires_at' => now()->addDays(14),
        ]);

        $user->forceDelete();
        $this->assertSame(0, RefreshToken::count());
    }
}