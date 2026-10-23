<?php

/**
 * Beem Africa SMS configuration.
 *
 * Populated fully in Phase 13. Credentials come from .env only.
 */

return [
    'api_key'    => env('BEEM_API_KEY'),
    'secret_key' => env('BEEM_SECRET_KEY'),
    'sender_id'  => env('BEEM_SENDER_ID', 'ODS'),
    'base_url'   => env('BEEM_BASE_URL', 'https://apisms.beem.africa/v1'),
];