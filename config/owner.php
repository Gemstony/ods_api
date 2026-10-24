<?php

/**
 * Bootstrap Owner account.
 *
 * The ODS document specifies a single Owner with full platform control.
 * These credentials seed that account once, on `php artisan db:seed`.
 *
 * Production deployment must override via .env before seeding.
 * Default password is a placeholder — the seeder warns if it's still in
 * place, which prevents accidental production deployment with defaults.
 */

return [
    'name'     => env('OWNER_NAME', 'scar'),
    'email'    => env('OWNER_EMAIL', 'scar@gmail.com'),
    'password' => env('OWNER_PASSWORD', 'scar123'),
    'phone'    => env('OWNER_PHONE', '0653709810'),

    /**
     * If true, the seeder logs a warning when the default password is used.
     * Set to false in environments where the default is acceptable (e.g.,
     * a throwaway local dev DB).
     */
    'warn_on_default_password' => env('OWNER_WARN_ON_DEFAULT_PASSWORD', true),
];