<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Seeds the single Owner account from config('owner.*').
 *
 * Idempotent — if a user with the configured email already exists, nothing
 * is created and nothing is overwritten. This protects a real production
 * Owner account from being reset by re-seeding.
 */
class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        $name = (string) config('owner.name');
        $email = (string) config('owner.email');
        $password = (string) config('owner.password');
        $phone = (string) config('owner.phone');

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Owner already exists ({$email}) — skipping.");
            return;
        }

        $ownerRole = Role::where('name', Role::OWNER)->first();
        if (!$ownerRole) {
            $this->command?->error(
                'Owner role not found. Ensure RoleSeeder runs before OwnerSeeder.',
            );
            return;
        }

        $usingDefaultPassword = ($password === 'ChangeMe123!');
        if ($usingDefaultPassword && config('owner.warn_on_default_password')) {
            Log::warning(
                'OwnerSeeder: creating Owner with the DEFAULT password. '
                . 'Set OWNER_PASSWORD in .env before deploying to production.',
            );
            $this->command?->warn(
                '⚠  Owner created with default password "ChangeMe123!". '
                . 'Change it immediately in production.',
            );
        }

        User::create([
            'role_id' => $ownerRole->id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make($password),
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->command?->info("Owner created: {$email}");
    }
}