<?php

/**
 * Beem Africa SMS configuration (Phase 13.2).
 *
 * Credentials come from .env only. The sender ID must be an ACTIVE
 * sender registered in the Beem dashboard — the default `INFO` sender
 * is deactivated and would produce error 111 on every send.
 *
 * Base URLs are hardcoded to the current Beem endpoints; changing them
 * is a `git diff` away if Beem ever migrates.
 */
return [

    // ─── Credentials ─────────────────────────────────────────
    'api_key'    => env('BEEM_API_KEY', ''),
    'secret_key' => env('BEEM_SECRET_KEY', ''),

    // ─── Sender ─────────────────────────────────────────────
    // Must be an ACTIVE sender. Refuse to dispatch otherwise.
    'sender_id'  => env('BEEM_SENDER_ID', ''),

    // ─── Endpoints ──────────────────────────────────────────
    'base_url'   => env('BEEM_BASE_URL', 'https://apisms.beem.africa/v1'),
    'delivery_reports_url' => env(
        'BEEM_DELIVERY_REPORTS_URL',
        'https://dlrapi.beem.africa/public/v1',
    ),

    // ─── HTTP timeouts (seconds) ────────────────────────────
    'timeout'         => (int) env('BEEM_TIMEOUT', 30),
    'connect_timeout' => (int) env('BEEM_CONNECT_TIMEOUT', 10),

    // ─── Throughput ─────────────────────────────────────────
    // Local self-imposed send rate. Beem does not publish a hard limit,
    // but production stability requires one. Increase only after
    // confirming the account's actual capacity with Beem support.
    'rate_limit_per_second' => (int) env('BEEM_RATE_LIMIT_PER_SECOND', 10),
];