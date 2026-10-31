<?php

namespace Tests\Feature\Owner;

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
        // Only roles are needed — controllers don't touch permissions.
        (new RoleSeeder())->run();
    }

    // ─── Fixtures ─────────────────────────────────────────────
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
            'name' => 'Test Branch',
            'code' => 'TST-01',
            'address' => '123 Test Street, Dar es Salaam',
            'latitude' => -6.79,
            'longitude' => 39.20,
            'operating_hours' => [
                'monday' => ['open' => '08:00', 'close' => '18:00'],
                'sunday' => null,
            ],
            'status' => 'active',
        ], $overrides);
    }

    // ─── Authorization ────────────────────────────────────────
    public function test_all_endpoints_require_authentication(): void
    {
        $branch = Branch::factory()->create();

        $this->getJson('/api/v1/owner/branches')->assertStatus(401);
        $this->postJson('/api/v1/owner/branches', [])->assertStatus(401);
        $this->getJson("/api/v1/owner/branches/{$branch->id}")->assertStatus(401);
        $this->putJson("/api/v1/owner/branches/{$branch->id}", [])->assertStatus(401);
    }

    public function test_all_endpoints_reject_non_owner_roles(): void
    {
        $manager = $this->manager();
        $branch = Branch::factory()->create();

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/owner/branches')
            ->assertStatus(403);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/owner/branches', $this->validPayload())
            ->assertStatus(403);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/owner/branches/{$branch->id}")
            ->assertStatus(403);

        $this->actingAs($manager, 'api')
            ->putJson("/api/v1/owner/branches/{$branch->id}", $this->validPayload())
            ->assertStatus(403);
    }

    // ─── index ────────────────────────────────────────────────
    public function test_index_returns_all_branches_paginated(): void
    {
        $owner = $this->owner();
        Branch::factory()->count(3)->create();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/branches');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 3)
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => [
                            'id', 'name', 'code', 'address',
                            'latitude', 'longitude', 'status',
                            'created_at', 'updated_at',
                        ],
                    ],
                    'current_page',
                    'total',
                    'per_page',
                ],
            ]);
    }

    public function test_index_filters_by_status(): void
    {
        $owner = $this->owner();
        Branch::factory()->count(2)->create(['status' => Branch::STATUS_ACTIVE]);
        Branch::factory()->create(['status' => Branch::STATUS_INACTIVE]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/branches?status=active');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.total', 2);
    }

    public function test_index_ignores_unknown_status_filter(): void
    {
        $owner = $this->owner();
        Branch::factory()->count(2)->create();

        // Invalid status → treated as "no filter" (returns all branches).
        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/branches?status=bogus');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.total', 2);
    }

    public function test_index_caps_per_page_at_100(): void
    {
        $owner = $this->owner();
        Branch::factory()->count(5)->create();

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/branches?per_page=999999');

        $response->assertStatus(200);
        $this->assertSame(100, $response->json('data.per_page'));
    }

    public function test_index_orders_by_name(): void
    {
        $owner = $this->owner();
        Branch::factory()->create(['name' => 'Zeta Branch']);
        Branch::factory()->create(['name' => 'Alpha Branch']);
        Branch::factory()->create(['name' => 'Mu Branch']);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/branches');

        $names = array_column($response->json('data.data'), 'name');
        $this->assertSame(['Alpha Branch', 'Mu Branch', 'Zeta Branch'], $names);
    }

    // ─── store ────────────────────────────────────────────────
    public function test_store_creates_branch_with_owner_as_creator(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/branches', $this->validPayload());

        $response
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Branch created.')
            ->assertJsonPath('data.name', 'Test Branch')
            ->assertJsonPath('data.code', 'TST-01')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.created_by', $owner->id);

        $this->assertDatabaseHas('branches', [
            'code' => 'TST-01',
            'name' => 'Test Branch',
            'status' => 'active',
            'created_by' => $owner->id,
        ]);
    }

    public function test_store_defaults_status_to_active_when_omitted(): void
    {
        $owner = $this->owner();
        $payload = $this->validPayload();
        unset($payload['status']);

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/branches', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_store_validates_required_fields(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/branches', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => ['name', 'code', 'address', 'latitude', 'longitude'],
            ]);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $owner = $this->owner();
        Branch::factory()->create(['code' => 'TST-01']);

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/branches', $this->validPayload(['code' => 'TST-01']))
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'This branch code is already in use.');
    }

    public function test_store_rejects_out_of_range_coordinates(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/branches', $this->validPayload(['latitude' => 100]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['latitude']]);

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/branches', $this->validPayload([
                'code' => 'TST-02',
                'longitude' => -200,
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['longitude']]);
    }

    public function test_store_rejects_invalid_status(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/branches', $this->validPayload(['status' => 'renovating']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_store_rejects_code_with_invalid_characters(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/owner/branches', $this->validPayload(['code' => 'TST 01!']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['code']]);
    }

    // ─── show ─────────────────────────────────────────────────
    public function test_show_returns_branch(): void
    {
        $owner = $this->owner();
        $branch = Branch::factory()->create();

        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/owner/branches/{$branch->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $branch->id)
            ->assertJsonPath('data.code', $branch->code);
    }

    public function test_show_returns_404_for_missing_branch(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->getJson('/api/v1/owner/branches/99999')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_show_returns_404_for_soft_deleted_branch(): void
    {
        $owner = $this->owner();
        $branch = Branch::factory()->create();
        $branch->delete(); // soft delete

        $this->actingAs($owner, 'api')
            ->getJson("/api/v1/owner/branches/{$branch->id}")
            ->assertStatus(404);
    }

    // ─── update ───────────────────────────────────────────────
    public function test_update_changes_branch_fields(): void
    {
        $owner = $this->owner();
        $branch = Branch::factory()->create([
            'name' => 'Old Name',
            'code' => 'OLD-01',
        ]);

        $response = $this->actingAs($owner, 'api')
            ->putJson("/api/v1/owner/branches/{$branch->id}", $this->validPayload([
                'name' => 'New Name',
                'code' => 'NEW-01',
                'status' => 'inactive',
            ]));

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'Branch updated.')
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.code', 'NEW-01')
            ->assertJsonPath('data.status', 'inactive');

        $fresh = $branch->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('NEW-01', $fresh->code);
        $this->assertSame('inactive', $fresh->status);
    }

    public function test_update_allows_keeping_own_code(): void
    {
        $owner = $this->owner();
        $branch = Branch::factory()->create(['code' => 'KEEP-01']);

        $this->actingAs($owner, 'api')
            ->putJson("/api/v1/owner/branches/{$branch->id}", $this->validPayload([
                'code' => 'KEEP-01',
                'name' => 'Updated Name',
            ]))
            ->assertStatus(200);
    }

    public function test_update_rejects_code_that_belongs_to_another_branch(): void
    {
        $owner = $this->owner();
        Branch::factory()->create(['code' => 'TAKEN-01']);
        $branch = Branch::factory()->create(['code' => 'MINE-01']);

        $this->actingAs($owner, 'api')
            ->putJson("/api/v1/owner/branches/{$branch->id}", $this->validPayload([
                'code' => 'TAKEN-01',
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['code']]);
    }

    public function test_update_returns_404_for_missing_branch(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'api')
            ->putJson('/api/v1/owner/branches/99999', $this->validPayload())
            ->assertStatus(404);
    }

    public function test_update_requires_status(): void
    {
        $owner = $this->owner();
        $branch = Branch::factory()->create();
        $payload = $this->validPayload();
        unset($payload['status']);

        $this->actingAs($owner, 'api')
            ->putJson("/api/v1/owner/branches/{$branch->id}", $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    }
}