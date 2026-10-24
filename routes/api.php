<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ODS API — v1
|--------------------------------------------------------------------------
|
| All paths resolve to `/api/v1/...` (Laravel prepends `/api` via
| install:api, and we add `/v1` here).
|
*/

Route::prefix('v1')->group(function () {

    // ─── Diagnostics ──────────────────────────────────────────
    Route::get('/health', [HealthController::class, 'index']);

    // ─── Authentication ───────────────────────────────────────
    Route::prefix('auth')->group(function () {

        // Public — rate-limited by dedicated limiters
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login');

        Route::post('/refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:refresh');

        // Protected — requires a valid access token
        Route::middleware('auth:api')->group(function () {
            Route::post('/logout',     [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);
            Route::get('/me',          [AuthController::class, 'me']);
        });
    });
});