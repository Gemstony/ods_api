<?php

/**
 * Cross-Origin Resource Sharing.
 *
 * Development: allow all origins so Flutter web (Chrome on any port) can
 *   talk to the Laravel dev server without CORS errors.
 *
 * Production (Phase 15): tighten `allowed_origins` to the app's official
 *   domains and turn `supports_credentials` on if cookies are introduced.
 */

return [
    'paths'                    => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods'          => ['*'],
    'allowed_origins'          => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers'          => ['*'],
    'exposed_headers'          => [],
    'max_age'                  => 0,
    'supports_credentials'     => false,
];