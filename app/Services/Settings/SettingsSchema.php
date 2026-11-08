<?php

namespace App\Services\Settings;

/**
 * The authoritative schema for every updatable setting.
 *
 * Purpose:
 *   - The API uses this to validate incoming values before persisting.
 *   - Adding a new setting requires ONE change here (plus the seeder).
 *   - Unknown keys are rejected — no silent no-ops for typos.
 *
 * The schema mirrors the seeders from Phase 2.8. If a seeder adds a new
 * setting, add its rule here in the same commit.
 *
 * Values are Laravel validation rule strings applied to the `value` field.
 */
class SettingsSchema
{
    public const RULES = [
        // ─── Location ─────────────────────────────────────────
        'location.pickup_radius_km' =>
            'required|numeric|min:0|max:500',
        'location.location_freshness_minutes' =>
            'required|integer|min:0|max:120',
        'location.assignment_timeout_minutes' =>
            'required|integer|min:1|max:60',

        // ─── Batching ─────────────────────────────────────────
        // batching.enabled                   kill switch (10.1+)
        // batching.window_minutes            fixed window length (10.1)
        // batching.early_closure_minutes     reserved (unused in fixed mode)
        // batching.max_batch_size            reserved (unused in fixed mode)
        'batching.enabled' =>
            'required|boolean',
        'batching.window_minutes' =>
            'required|integer|min:1|max:120',
        'batching.early_closure_minutes' =>
            'required|integer|min:0|max:60',
        'batching.max_batch_size' =>
            'required|integer|min:1|max:100',

        // ─── Finance ──────────────────────────────────────────
        'finance.payout_schedule' =>
            'required|string|in:daily,weekly,biweekly,monthly',
        'finance.payout_day' =>
            'required|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
        'finance.expense_categories' =>
            'required|array|min:1',
        'finance.expense_categories.*' =>
            'string|max:50',

        // ─── Payments ─────────────────────────────────────────
        'payments.enabled_methods' =>
            'required|array|min:1',
        'payments.enabled_methods.*' =>
            'string|in:clickpesa,cash,manual',
        'payments.refund_window_hours' =>
            'required|integer|min:0|max:720',
        'payments.max_manual_refund_tsh' =>
            'required|integer|min:0|max:10000000',

        // ─── Security ─────────────────────────────────────────
        'security.verification_required_pickup' =>
            'required|boolean',
        'security.verification_required_delivery' =>
            'required|boolean',
        'security.max_verification_attempts' =>
            'required|integer|min:1|max:10',
        'security.verification_code_ttl_hours' =>
            'required|integer|min:1|max:720',

        // ─── Notifications ────────────────────────────────────
        'notifications.sms_enabled' =>
            'required|boolean',
        'notifications.push_enabled' =>
            'required|boolean',
        'notifications.email_enabled' =>
            'required|boolean',

        // ─── App (public) ─────────────────────────────────────
        'app.min_supported_version' =>
            'required|string|max:20|regex:/^\d+\.\d+\.\d+$/',
        'app.maintenance_mode' =>
            'required|boolean',
        'app.app_name' =>
            'required|string|min:1|max:50',
    ];

    public static function ruleFor(string $key): ?string
    {
        return self::RULES[$key] ?? null;
    }

    public static function isKnown(string $key): bool
    {
        return isset(self::RULES[$key]);
    }

    public static function knownKeys(): array
    {
        return array_keys(self::RULES);
    }
}