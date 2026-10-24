<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seeds default settings across all ODS-configurable categories.
 *
 * Categories (matching the doc's "What must be configurable outside the
 * code?" list):
 *   location, batching, finance, payments, security, notifications, app
 *
 * Pricing and rewards are handled by PricingConfig (2.4) — not here.
 * Everything else lives in this key-value store.
 *
 * All `app.*` settings are is_public=true so unauthenticated clients can
 * read them (e.g., for a minimum-supported-version gate).
 *
 * Idempotent — uses Setting::set() which upserts by key.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // ─── Location ─────────────────────────────────────────
            ['location.pickup_radius_km',               5.0,   'location',      'Maximum distance (km) to consider a rider for a pickup', false],
            ['location.location_freshness_minutes',     10,    'location',      'Minutes before a rider\'s location is considered stale', false],
            ['location.assignment_timeout_minutes',     5,     'location',      'Minutes a rider has to accept an assignment', false],

            // ─── Batching ─────────────────────────────────────────
            ['batching.window_minutes',                 20,    'batching',      'Regional delivery batching window (minutes)', false],
            ['batching.early_closure_minutes',          5,     'batching',      'Close a batching window early if no new orders arrive', false],
            ['batching.max_batch_size',                 10,    'batching',      'Maximum deliveries per batch', false],

            // ─── Finance ──────────────────────────────────────────
            ['finance.payout_schedule',                 'weekly', 'finance',    'Payout schedule: daily, weekly, biweekly, monthly', false],
            ['finance.payout_day',                      'friday', 'finance',    'Day payouts are processed (for weekly/biweekly)', false],
            ['finance.expense_categories',              ['fuel', 'salary', 'rent', 'utilities', 'supplies', 'maintenance', 'transport_provider', 'other'], 'finance', 'Categories for operating expenses', false],

            // ─── Payments ─────────────────────────────────────────
            ['payments.enabled_methods',                ['clickpesa'], 'payments', 'Enabled payment methods', false],
            ['payments.refund_window_hours',            24,    'payments',      'Hours after payment during which a refund is possible', false],
            ['payments.max_manual_refund_tsh',          50000, 'payments',      'Maximum manual refund (TZS) a Manager can approve', false],

            // ─── Security ─────────────────────────────────────────
            ['security.verification_required_pickup',   true,  'security',      'Require DISTRIBUTOR_CODE at pickup', false],
            ['security.verification_required_delivery', true,  'security',      'Require RECIPIENT_CODE at delivery', false],
            ['security.max_verification_attempts',      3,     'security',      'Maximum wrong-code attempts before code expires', false],
            ['security.verification_code_ttl_hours',    72,    'security',      'How long a verification code remains valid', false],

            // ─── Notifications ────────────────────────────────────
            ['notifications.sms_enabled',               true,  'notifications', 'Send SMS notifications via Beem Africa', false],
            ['notifications.push_enabled',              true,  'notifications', 'Send push notifications via FCM', false],
            ['notifications.email_enabled',             false, 'notifications', 'Send email notifications', false],

            // ─── App ──────────────────────────────────────────────
            ['app.min_supported_version',               '0.1.0', 'app',         'Minimum client version allowed to operate', true],
            ['app.maintenance_mode',                    false,   'app',         'If true, clients see a maintenance screen', true],
            ['app.app_name',                            'ODS',   'app',         'Application display name', true],
        ];

        foreach ($defaults as [$key, $value, $category, $description, $isPublic]) {
            Setting::set($key, $value, $category, null, $description, $isPublic);
        }

        $this->command?->info('Default settings seeded.');
    }
}