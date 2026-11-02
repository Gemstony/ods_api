<?php

namespace Tests\Feature\Manager;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerStaffControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleSeeder())->run();
    }

    protected function manager(?Branch $branch = null): User
    {
        $branch ??= Branch::factory()->create();
        return User::factory()->role(Role::MANAGER)->atBranch($branch)->create();
    }

    protected function receiverPayload(array $overrides = []): array
    {
        return array_merge([
            'role' => 'receiver',
            'name' => 'New Receiver',
            'email' => 'receiver@ods.test',
            'phone' => '0755123456',
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
        ], $overrides);
    }

    protected function riderPayload(array $overrides = []): array
    {
        return array_merge([
            'role' => 'rider',
            'name' => 'New Rider',
            'email' => 'rider@ods.test',
            'phone' => '0767123456',
            'password' => 'SecretPass123!',
            'password_confirmation' => 'SecretPass123!',
            'nida' => '19900101123456789012',
            'sex' => 'male',
            'date_of_birth' => '1990-01-01',
            'vehicle_type' => 'Motorcycle',
            'vehicle_color' => 'Red',
            'vehicle_ownership' => 'owned',
            'plate_number' => 'T123ABC',
        ], $overrides);
    }

    // ─── Auth ─────────────────────────────────────────────────
    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/manager/staff')->assertStatus(401);
        $this->postJson('/api/v1/manager/staff', [])->assertStatus(401);
    }

    public function test_rejects_owner_and_rider_roles(): void
    {
        $owner = User::factory()->role(Role::OWNER)->create();
        $this->actingAs($owner, 'api')->getJson('/api/v1/manager/staff')->assertStatus(403);

        $rider = User::factory()->role(Role::RIDER)->create();
        $this->actingAs($rider, 'api')->getJson('/api/v1/manager/staff')->assertStatus(403);
    }

    // ─── index ────────────────────────────────────────────────
    public function test_index_returns_only_own_branch_staff(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        $manager = $this->manager($own);

        // Own branch staff
        User::factory()->role(Role::RECEIVER)->atBranch($own)->create(['name' => 'Own Receiver']);
        User::factory()->role(Role::RIDER)->atBranch($own)->create(['name' => 'Own Rider']);
        // Other branch staff — should not appear
        User::factory()->role(Role::RIDER)->atBranch($other)->create(['name' => 'Other Rider']);

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/staff');

        $response->assertStatus(200);
        $names = array_column($response->json('data.data'), 'name');
        $this->assertContains('Own Receiver', $names);
        $this->assertContains('Own Rider', $names);
        $this->assertNotContains('Other Rider', $names);
    }

    public function test_index_excludes_managers_and_owners(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);

        // Another manager in the same branch (unusual but possible)
        User::factory()->role(Role::MANAGER)->atBranch($branch)->create();
        // A receiver
        User::factory()->role(Role::RECEIVER)->atBranch($branch)->create();

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/staff');

        // Only the receiver should appear, not the other manager
        $roles = array_column($response->json('data.data'), 'role');
        $this->assertNotContains('manager', $roles);
        $this->assertNotContains('owner', $roles);
    }

    public function test_index_filters_by_role(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        User::factory()->role(Role::RECEIVER)->atBranch($branch)->count(2)->create();
        User::factory()->role(Role::RIDER)->atBranch($branch)->count(3)->create();

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/manager/staff?role=rider');

        $response->assertStatus(200);
        foreach ($response->json('data.data') as $row) {
            $this->assertSame('rider', $row['role']);
        }
    }

    // ─── show ─────────────────────────────────────────────────
    public function test_show_returns_staff_in_own_branch(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $staff = User::factory()->role(Role::RIDER)->atBranch($branch)->create();

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/manager/staff/{$staff->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $staff->id);
    }

    public function test_show_returns_404_for_other_branch_staff(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        $manager = $this->manager($own);
        $staff = User::factory()->role(Role::RIDER)->atBranch($other)->create();

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/manager/staff/{$staff->id}")
            ->assertStatus(404);
    }

    // ─── store ────────────────────────────────────────────────
    public function test_store_creates_receiver_in_manager_branch(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);

        $response = $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/staff', $this->receiverPayload());

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.role', 'receiver')
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_store_creates_rider_with_rider_fields(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);

        $response = $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/staff', $this->riderPayload());

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.role', 'rider')
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.plate_number', 'T123ABC');
    }

    public function test_store_rejects_manager_role(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/staff', $this->receiverPayload([
                'role' => 'manager',
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['role']]);
    }

    public function test_store_rejects_owner_role(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/staff', $this->receiverPayload([
                'role' => 'owner',
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['role']]);
    }

    public function test_store_forces_branch_from_manager_not_payload(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        $manager = $this->manager($own);

        // Even if the payload tries to target another branch, the
        // controller must overwrite it with the manager's branch.
        $response = $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/staff', $this->receiverPayload([
                'branch_id' => $other->id,
            ]));

        $response->assertStatus(201);
        $staff = User::where('email', 'receiver@ods.test')->firstOrFail();
        $this->assertSame($own->id, $staff->branch_id);
    }

    public function test_store_requires_rider_specific_fields(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/manager/staff', [
                'role' => 'rider',
                'name' => 'No Details',
                'email' => 'no-details@ods.test',
                'phone' => '0700123456',
                'password' => 'SecretPass123!',
                'password_confirmation' => 'SecretPass123!',
            ])
            ->assertStatus(422)
            ->assertJsonStructure([
                'errors' => ['nida', 'sex', 'date_of_birth', 'vehicle_type'],
            ]);
    }

    // ─── update ───────────────────────────────────────────────
    public function test_update_changes_staff_fields(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $rider = User::factory()->role(Role::RIDER)->atBranch($branch)->create([
            'name' => 'Old',
            'phone' => '0711111111',
        ]);

        $this->actingAs($manager, 'api')
            ->putJson("/api/v1/manager/staff/{$rider->id}", [
                'name' => 'New',
                'phone' => '0722222222',
                'nida' => '19900101123456789012',
                'sex' => 'male',
                'date_of_birth' => '1990-01-01',
                'vehicle_type' => 'Car',
                'vehicle_color' => 'Blue',
                'vehicle_ownership' => 'rented',
                'plate_number' => 'T999XYZ',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.phone', '0722222222');
    }

    public function test_update_returns_404_for_other_branch_staff(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        $manager = $this->manager($own);
        $staff = User::factory()->role(Role::RIDER)->atBranch($other)->create();

        $this->actingAs($manager, 'api')
            ->putJson("/api/v1/manager/staff/{$staff->id}", [
                'name' => 'X',
                'phone' => '0755123456',
            ])
            ->assertStatus(404);
    }

    // ─── status ───────────────────────────────────────────────
    public function test_suspend_staff_revokes_tokens(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $receiver = User::factory()->role(Role::RECEIVER)->atBranch($branch)->create();

        \App\Models\RefreshToken::create([
            'user_id' => $receiver->id,
            'token_hash' => hash('sha256', 'tok'),
            'expires_at' => now()->addDays(14),
        ]);

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/manager/staff/{$receiver->id}/status", [
                'status' => 'suspended',
            ])
            ->assertStatus(200);

        $this->assertSame(User::STATUS_SUSPENDED, $receiver->fresh()->status);
        $this->assertNotNull(
            \App\Models\RefreshToken::where('user_id', $receiver->id)->first()->revoked_at,
        );
    }

    public function test_cannot_suspend_self(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/manager/staff/{$manager->id}/status", [
                'status' => 'suspended',
            ])
            ->assertStatus(404); // self is a manager, not staff → 404
    }

    // ─── reset password ───────────────────────────────────────
    public function test_reset_password_succeeds(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->manager($branch);
        $rider = User::factory()->role(Role::RIDER)->atBranch($branch)->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/manager/staff/{$rider->id}/reset-password", [
                'password' => 'NewSecretPass!',
                'password_confirmation' => 'NewSecretPass!',
            ])
            ->assertStatus(200);

        $this->assertTrue(\Hash::check('NewSecretPass!', $rider->fresh()->password));
    }

    public function test_reset_password_returns_404_for_other_branch(): void
    {
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        $manager = $this->manager($own);
        $staff = User::factory()->role(Role::RIDER)->atBranch($other)->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/manager/staff/{$staff->id}/reset-password", [
                'password' => 'NewSecretPass!',
                'password_confirmation' => 'NewSecretPass!',
            ])
            ->assertStatus(404);
    }
}