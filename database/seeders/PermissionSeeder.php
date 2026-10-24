<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Seeds the 12 permissions that back role capabilities.
 *
 * Naming: `<verb>_<noun>` — used by `$user->hasPermission('manage_users')`.
 * Coarse-grained by design; more can be added later without schema changes
 * (they're rows, not code).
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // ─── Owner-level ──────────────────────────────────────
            'manage_users'            => 'Create, edit, suspend, and assign roles to users',
            'manage_branches'         => 'Create and manage branches and their managers',
            'manage_settings'         => 'Change platform-wide settings (location, batching, security)',
            'manage_finance'          => 'View and reconcile financial ledgers, approve payouts',
            'view_audit_logs'         => 'View the audit log for compliance and debugging',
            'resolve_disputes'        => 'Review, respond to, and resolve disputes',

            // ─── Manager-level ───────────────────────────────────
            'manage_staff'            => 'Create, edit, and deactivate branch staff (Receivers, Riders)',
            'view_branch_operations'  => 'View deliveries, packages, and staff within own branch',

            // ─── Receiver-level ──────────────────────────────────
            'handle_packages'         => 'Receive, verify, and release packages at a branch',
            'verify_shipments'        => 'Verify product and quantity details against shipments',

            // ─── Distributor-level ───────────────────────────────
            'create_delivery_requests'=> 'Create and pay for delivery requests',

            // ─── Rider-level ─────────────────────────────────────
            'accept_deliveries'       => 'Accept and execute assigned delivery tasks',
        ];

        foreach ($permissions as $name => $description) {
            Permission::firstOrCreate(
                ['name' => $name],
                ['description' => $description],
            );
        }
    }
}