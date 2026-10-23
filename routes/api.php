<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ODS API — v1
|--------------------------------------------------------------------------
|
| Laravel 11 applies the `/api` prefix automatically (set by install:api in
| bootstrap/app.php). Adding Route::prefix('v1') here means every path
| resolves to `/api/v1/...`, matching the Flutter app's AppConfig.apiBaseUrl.
|
| Populated incrementally:
|   1.10 → GET /health
|   Phase 3  → /auth/*
|   Phase 5–9 → per-role resources
|
*/

Route::prefix('v1')->group(function () {
    Route::get('/ping', fn () => response()->json([
        'success' => true,
        'message' => 'pong',
    ]));
});