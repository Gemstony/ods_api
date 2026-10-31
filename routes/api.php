<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Owner\BranchController;
use App\Http\Controllers\Api\V1\Owner\BranchManagerController;
use App\Http\Controllers\Api\V1\Owner\UserController;
use App\Http\Controllers\Api\V1\Owner\PricingConfigController;
use App\Http\Controllers\Api\V1\Owner\SettingsController;
use App\Http\Controllers\Api\V1\Public\PublicSettingsController;

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


        Route::post('/register/distributor', [AuthController::class, 'registerDistributor'])
            ->middleware('throttle:register');

        Route::post('/refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:refresh');

        // Protected — requires a valid access token
        Route::middleware('auth:api')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

        // ─── Public ───────────────────────────────────────────────
    Route::get('/settings/public', [PublicSettingsController::class, 'index'])
        ->middleware('throttle:60,1');


       // ─── Owner (Phase 5) ──────────────────────────────────────
    Route::middleware(['auth:api', 'role:owner'])
        ->prefix('owner')
        ->group(function () {
            // Branches
            Route::apiResource('branches', BranchController::class)
                ->only(['index', 'store', 'show', 'update']);

            // Branch manager assignment
            Route::post('branches/{branch}/assign-manager', [BranchManagerController::class, 'assign']);
            Route::delete('branches/{branch}/assign-manager', [BranchManagerController::class, 'unassign']);
            Route::get('branches/{branch}/managers', [BranchManagerController::class, 'index']);

            // Users
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::post('users/{user}/approve', [UserController::class, 'approve']);
            Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);

            // Settings (bulk before key-based so `PUT /settings` doesn't
            // get swallowed by the `{key}` route)
            Route::get('settings', [SettingsController::class, 'index']);
            Route::put('settings', [SettingsController::class, 'bulkUpdate']);
            Route::get('settings/{key}', [SettingsController::class, 'show'])
                ->where('key', '[A-Za-z0-9._-]+');
            Route::put('settings/{key}', [SettingsController::class, 'update'])
                ->where('key', '[A-Za-z0-9._-]+');

            // Pricing config
            Route::get('pricing-config', [PricingConfigController::class, 'show']);
            Route::put('pricing-config', [PricingConfigController::class, 'update']);
        });
});