<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the five fixed ODS roles.
 *
 * Role names are the stable identifiers used throughout the codebase
 * (matching Role::OWNER, Role::MANAGER, etc.). Display names are what the
 * UI shows.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            Role::OWNER       => 'Owner',
            Role::MANAGER     => 'Manager',
            Role::DISTRIBUTOR => 'Distributor',
            Role::RIDER       => 'Delivery Agent',
            Role::RECEIVER    => 'Receiver',
        ];

        foreach ($roles as $name => $displayName) {
            Role::firstOrCreate(
                ['name' => $name],
                ['display_name' => $displayName],
            );
        }
    }
}