<?php

namespace Tests\Feature\Owner;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
    }

    protected function owner(): User
    {
        return User::factory()->role(Role::OWNER)->create();
    }

    protected function branch(): Branch
    {
        return Branch::factory()->create();
    }

    protected function managerPayload(int $branchId, array $overrides = []): array
    {
        return array_merge([
            'role' => 'manager',
            'name' => 'Jane Manager',
            'email' => 'jane.manager@ods.test',
            'phone' => '0755123456',
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
            'branch_id' => $branchId,
        ], $overrides);
    }

    protected function riderPayload(int $branchId, array $overrides = []): array
    {
        return array_merge([
            'role' => 'rider',
            'name' => 'Tom Rider',
            'email' => 'tom.rider@ods.test',
            'phone' => '0767123456',
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
            'branch_id' => $branchId,
            'nida' => '19900101123456789012',
            'sex' => 'male',
            'date_of_birth' => '1990-01-01',
            'vehicle_type' => 'Motorcycle',
            'vehicle_color' => 'Red',
            'vehicle_ownership' => 'owned',
            'plate_number' => 'T123ABC',
        ], $overrides);
    }

    // ─── Authorization ────────────────────────────────────────
    public function test_all_endpoints_require_authentication(): void
    {
        $target = User::factory()->role(Role::MANAGER)->create();

        $this->getJson('/api/v1/owner/users')->assertStatus(401);
        $this->postJson('/api/v1/owner/users', [])->assertStatus(401);
        $this->getJson("/api/v1/owner/users/{$target->id}")->assertStatus(401);
        $this->putJson("/api/v1/owner/users/{$target->id}", [])->assertStatus(401);
        $this->patchJson("/api/v1/owner/users/{$target->id}/status", [])->assertStatus(401);
        $this->postJson("/api/v1/owner/users/{$target->id}/approve")->assertStatus(401);
        $this->postJson("/api/v1/owner/users/{$target->id}/reset-password", [])->assertStatus(401);
    }

    public function test_all_endpoints_reject_non_owner_roles(): void
    {
        $manager = User::factory()->role(Role::MANAGER)->create();
        $target = User::factory()->role(Role::RIDER)->create();

        $this->actingAs($manager, 'api')->getJson('/api/v1/owner/users')->assertStatus(403);
        $this->actingAs($manager, 'api')->postJson('/api/v1/owner/users', [])->assertStatus(403);
        $this->actingAs($manager, 'api')->getJson("/api/v1/owner/users/{$target->id}")->assertStatus(403);
        $this->actingAs($manager, 'api')->patchJson("/api/v1/owner/users/{$target->id}/status", [])->assertStatus(403);
    }

    // ─── index ────────────────────────────────────────────────
    public function test_index_returns_users_with_filters(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();
        $m1 = User::factory()->role(Role::MANAGER)->atBranch($branch)->create();
        $r1 = User::factory()->role(Role::RIDER)->atBranch($branch)->create();
        User::factory()->role(Role::DISTRIBUTOR)->create();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/users');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => ['id', 'name', 'email', 'role', 'status', 'branch_id'],
                    ],
                    'total', 'per_page', 'current_page', 'last_page',
                ],
            ]);

        $this->assertGreaterThanOrEqual(4, $response->json('data.total'));
    }

    public function test_index_filters_by_role(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();
        User::factory()->role(Role::MANAGER)->atBranch($branch)->create();
        User::factory()->role(Role::RIDER)->atBranch($branch)->count(2)->create();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/users?role=rider');

        $response->assertStatus(200);
        foreach ($response->json('data.data') as $user) {
            $this->assertSame('rider', $user['role']);
        }
    }

    public function test_index_filters_by_status_pending(): void
    {
        $owner = $this->owner();
        User::factory()->role(Role::DISTRIBUTOR)->pending()->count(2)->create();
        User::factory()->role(Role::DISTRIBUTOR)->count(3)->create();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/users?pending_only=1');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.total', 2);
    }

    public function test_index_filters_by_branch(): void
    {
        $owner = $this->owner();
        $b1 = $this->branch();
        $b2 = Branch::factory()->create();
        User::factory()->role(Role::RIDER)->atBranch($b1)->count(2)->create();
        User::factory()->role(Role::RIDER)->atBranch($b2)->create();

        $response = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/owner/users?branch_id={$b1->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.total', 2);
    }

    public function test_index_search_matches_name_email_or_phone(): void
    {
        $owner = $this->owner();
        User::factory()->create(['name' => 'Unique Name Alpha']);
        User::factory()->create(['name' => 'Someone Else']);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/users?search=Unique');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('Unique Name Alpha', $response->json('data.data.0.name'));
    }

    // ─── show ─────────────────────────────────────────────────
    public function test_show_returns_user_with_branch(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();
        $user = User::factory()->role(Role::RIDER)->atBranch($branch)->create();

        $response = $this->actingAs($owner, 'api')
            ->getJson("/api/v1/owner/users/{$user->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'rider')
            ->assertJsonPath('data.branch.id', $branch->id);
    }

    public function test_show_returns_404_for_missing_user(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/users/99999')
            ->assertStatus(404);
    }

    // ─── store ────────────────────────────────────────────────
    public function test_store_creates_manager_and_assigns_to_branch(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();

        $response = $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/users', $this->managerPayload($branch->id));

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.status', 'active');

        $manager = User::where('email', 'jane.manager@ods.test')->firstOrFail();
        $this->assertSame($branch->id, $manager->branch_id);

        // BranchManager history row exists
        $this->assertDatabaseHas('branch_managers', [
            'branch_id' => $branch->id,
            'user_id' => $manager->id,
            'unassigned_at' => null,
        ]);
    }

    public function test_store_creates_receiver_with_dispatcher_flag(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();

        $response = $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/users', [
                'role' => 'receiver',
                'name' => 'Alice Receiver',
                'email' => 'alice.receiver@ods.test',
                'phone' => '0768123456',
                'password' => 'SecretPass123!',
                'password_confirmation' => 'SecretPass123!',
                'branch_id' => $branch->id,
                'dispatcher_enabled' => true,
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.role', 'receiver')
            ->assertJsonPath('data.dispatcher_enabled', true);
    }

    public function test_store_creates_rider_with_all_fields(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();

        $response = $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/users', $this->riderPayload($branch->id));

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.role', 'rider')
            ->assertJsonPath('data.nida', '19900101123456789012')
            ->assertJsonPath('data.vehicle_type', 'Motorcycle')
            ->assertJsonPath('data.plate_number', 'T123ABC');
    }

    public function test_store_rejects_owner_role(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/users', $this->managerPayload($branch->id, [
                'role' => 'owner',
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['role']]);
    }

    public function test_store_rejects_distributor_role(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/users', $this->managerPayload($branch->id, [
                'role' => 'distributor',
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['role']]);
    }

    public function test_store_requires_rider_specific_fields(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/users', [
                'role' => 'rider',
                'name' => 'No Details Rider',
                'email' => 'no.details@ods.test',
                'phone' => '0700123456',
                'password' => 'SecretPass123!',
                'password_confirmation' => 'SecretPass123!',
                'branch_id' => $branch->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure([
                'errors' => [
                    'nida', 'sex', 'date_of_birth', 'vehicle_type',
                    'vehicle_color', 'vehicle_ownership', 'plate_number',
                ],
            ]);
    }

    public function test_store_rejects_duplicate_email(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();
        User::factory()->create(['email' => 'jane.manager@ods.test']);

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/users', $this->managerPayload($branch->id))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_store_validates_phone_format(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/users', $this->managerPayload($branch->id, [
                'phone' => '12345',
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['phone']]);
    }

    public function test_store_requires_existing_branch(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/users', $this->managerPayload(99999))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['branch_id']]);
    }

    // ─── update ───────────────────────────────────────────────
    public function test_update_changes_basic_fields(): void
    {
        $owner = $this->owner();
        $branch = $this->branch();
        $rider = User::factory()->role(Role::RIDER)->atBranch($branch)->create([
            'name' => 'Old Name',
            'phone' => '0711111111',
        ]);

        $response = $this->actingAs($owner, 'api')
            ->putJson("/api/v1/owner/users/{$rider->id}", $this->riderPayload($branch->id, [
                'name' => 'New Name',
                'phone' => '0722222222',
            ]));

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.phone', '0722222222');
    }

    public function test_update_rejects_branch_change_for_manager(): void
    {
        $owner = $this->owner();
        $b1 = $this->branch();
        $b2 = Branch::factory()->create();
        $manager = User::factory()->role(Role::MANAGER)->atBranch($b1)->create();
        // Give them an active assignment so history is consistent.
        $b1->assignManager($manager, $owner);

        $this->actingAs($owner, 'api')
            ->putJson("/api/v1/owner/users/{$manager->id}", [
                'name' => 'Jane Manager',
                'phone' => '0755123456',
                'branch_id' => $b2->id,
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Use POST /owner/branches/{branch}/assign-manager to change a Manager\'s branch.',
            ]);
    }

    // ─── approve ──────────────────────────────────────────────
    public function test_approve_activates_pending_distributor(): void
    {
        $owner = $this->owner();
        $distributor = User::factory()->role(Role::DISTRIBUTOR)->pending()->create();

        $response = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/users/{$distributor->id}/approve");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(User::STATUS_ACTIVE, $distributor->fresh()->status);
    }

    public function test_approve_rejects_non_pending_user(): void
    {
        $owner = $this->owner();
        $active = User::factory()->role(Role::MANAGER)->create();

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/users/{$active->id}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Only pending accounts can be approved.']);
    }

    // ─── updateStatus ─────────────────────────────────────────
    public function test_suspend_user(): void
    {
        $owner = $this->owner();
        $user = User::factory()->role(Role::MANAGER)->create();

        $response = $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/owner/users/{$user->id}/status", [
                'status' => 'suspended',
            ]);

        $response->assertStatus(200)->assertJsonPath('data.status', 'suspended');
        $this->assertSame(User::STATUS_SUSPENDED, $user->fresh()->status);
    }

    public function test_activate_user(): void
    {
        $owner = $this->owner();
        $user = User::factory()->role(Role::MANAGER)->suspended()->create();

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/owner/users/{$user->id}/status", [
                'status' => 'active',
            ])
            ->assertStatus(200);

        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    public function test_cannot_change_own_status(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/owner/users/{$owner->id}/status", [
                'status' => 'suspended',
            ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'You cannot change your own account status.',
            ]);
    }

    public function test_cannot_suspend_owner(): void
    {
        $owner = $this->owner();
        $otherOwner = User::factory()->role(Role::OWNER)->create();

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/owner/users/{$otherOwner->id}/status", [
                'status' => 'suspended',
            ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => "The Owner's status cannot be changed.",
            ]);
    }

    public function test_suspend_revokes_refresh_tokens(): void
    {
        $owner = $this->owner();
        $user = User::factory()->role(Role::MANAGER)->create();

        // Simulate an active token
        \App\Models\RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'tok-' . $user->id),
            'expires_at' => now()->addDays(14),
        ]);

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/owner/users/{$user->id}/status", [
                'status' => 'suspended',
            ])
            ->assertStatus(200);

        $this->assertNotNull(
            \App\Models\RefreshToken::where('user_id', $user->id)->first()->revoked_at,
        );
    }

    // ─── resetPassword ────────────────────────────────────────
    public function test_reset_password(): void
    {
        $owner = $this->owner();
        $user = User::factory()->role(Role::MANAGER)->create();

        $response = $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/users/{$user->id}/reset-password", [
                'password' => 'NewSecretPass!',
                'password_confirmation' => 'NewSecretPass!',
            ]);

        $response->assertStatus(200);

        // Verify the new password works
        $fresh = $user->fresh();
        $this->assertTrue(\Hash::check('NewSecretPass!', $fresh->password));
    }

    public function test_reset_password_revokes_refresh_tokens(): void
    {
        $owner = $this->owner();
        $user = User::factory()->role(Role::MANAGER)->create();

        \App\Models\RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'tok-' . $user->id),
            'expires_at' => now()->addDays(14),
        ]);

        $this->actingAs($owner, 'api')
            ->postJson("/api/v1/owner/users/{$user->id}/reset-password", [
                'password' => 'NewSecretPass!',
                'password_confirmation' => 'NewSecretPass!',
            ])
            ->assertStatus(200);

        $this->assertNotNull(
            \App\Models\RefreshToken::where('user_id', $user->id)->first()->revoked_at,
        );
    }
}