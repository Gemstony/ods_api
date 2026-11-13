<?php

/**
 * ClickPesa payment gateway configuration (Phase 11).
 *
 * Never commit real credentials. The .env.example lists all keys; the
 * values live only in each developer's local .env and in production's
 * secret store.
 *
 * Base URL defaults to the sandbox so a fresh checkout cannot
 * accidentally hit live payments.
 */
return [

    // ─── Credentials ─────────────────────────────────────────
    'base_url' => env('CLICKPESA_BASE_URL', 'https://sandbox.clickpesa.com'),
    'client_id' => env('CLICKPESA_CLIENT_ID'),
    'client_secret' => env('CLICKPESA_CLIENT_SECRET'),
    'webhook_secret' => env('CLICKPESA_WEBHOOK_SECRET'),

    // ─── Defaults ────────────────────────────────────────────
    'currency' => env('CLICKPESA_CURRENCY', 'TZS'),

    // ─── HTTP timeouts (seconds) ─────────────────────────────
    'timeout' => (int) env('CLICKPESA_TIMEOUT', 30),
    'connect_timeout' => (int) env('CLICKPESA_CONNECT_TIMEOUT', 10),

    // ─── Token lifecycle ─────────────────────────────────────
    // ClickPesa issues tokens valid for 60 minutes. We refresh at 55 min
    // as a safety margin. Never increase above 59.
    'token_ttl_minutes' => (int) env('CLICKPESA_TOKEN_TTL_MINUTES', 55),

    // ─── Endpoints (relative to base_url) ────────────────────
    // Centralized so URL changes never require code edits. The client
    // uses these literals today; future flexibility at zero cost.
    'endpoints' => [
        'auth' => '/third-parties/generate-token',
        'preview' => '/third-parties/payments/preview-ussd-push-request',
        'initiate' => '/third-parties/payments/initiate-ussd-push-request',
        'query' => '/third-parties/payments/{orderReference}',
        'query_all' => '/third-parties/payments/all',
    ],
];