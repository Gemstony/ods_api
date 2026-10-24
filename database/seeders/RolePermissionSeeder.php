<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Attaches permissions to roles.
 *
 * `syncWithoutDetaching` is idempotent — re-running the seeder won't
 * create duplicates, and it preserves any permissions manually added
 * outside this seeder.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $mapping = [
            Role::OWNER => [
                'manage_users',
                'manage_branches',
                'manage_settings',
                'manage_finance',
                'view_audit_logs',
                'resolve_disputes',
            ],
            Role::MANAGER => [
                'manage_staff',
                'view_branch_operations',
                'resolve_disputes',
            ],
            Role::DISTRIBUTOR => [
                'create_delivery_requests',
            ],
            Role::RIDER => [
                'accept_deliveries',
            ],
            Role::RECEIVER => [
                'handle_packages',
                'verify_shipments',
            ],
        ];

        foreach ($mapping as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }

            $permissionIds = Permission::whereIn('name', $permissionNames)
                ->pluck('id')
                ->all();

            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }
}