<?php

namespace App\Services\Settings;

/**
 * The authoritative schema for every updatable setting.
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
        'batching.enabled' =>
            'required|boolean',
        'batching.window_minutes' =>
            'required|integer|min:1|max:120',
        'batching.early_closure_minutes' =>
            'required|integer|min:0|max:60',
        'batching.max_batch_size' =>
            'required|integer|min:1|max:100',

        // ─── Compensation (Phase 11.3) ───────────────────────
        'compensation.enabled' =>
            'required|boolean',
        'compensation.rider_baseline_percent' =>
            'required|numeric|min:0|max:100',
        'compensation.rider_bonus_percent' =>
            'required|numeric|min:0|max:100',
        'compensation.rider_bonus_enabled' =>
            'required|boolean',
        'compensation.dispatcher_model' =>
            'required|string|in:flat,percent,none',
        'compensation.dispatcher_flat_amount_tsh' =>
            'required|integer|min:0|max:100000',
        'compensation.dispatcher_percent' =>
            'required|numeric|min:0|max:100',
        'compensation.require_approval' =>
            'required|boolean',

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