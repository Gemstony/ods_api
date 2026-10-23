<?php

/**
 * ClickPesa payment gateway configuration.
 *
 * Populated fully in Phase 11. The client and webhook secrets are read
 * from .env — never committed. Base URL defaults to the sandbox so a
 * fresh checkout doesn't accidentally hit live payments.
 */

return [
    'base_url'       => env('CLICKPESA_BASE_URL', 'https://sandbox.clickpesa.com'),
    'client_id'      => env('CLICKPESA_CLIENT_ID'),
    'client_secret'  => env('CLICKPESA_CLIENT_SECRET'),
    'webhook_secret' => env('CLICKPESA_WEBHOOK_SECRET'),
    'currency'       => env('CLICKPESA_CURRENCY', 'TZS'),
];