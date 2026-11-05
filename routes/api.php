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
use App\Http\Controllers\Api\V1\Owner\DashboardController;
use App\Http\Controllers\Api\V1\Owner\FinanceController;
use App\Http\Controllers\Api\V1\Manager\ManagerBranchController;
use App\Http\Controllers\Api\V1\Manager\ManagerDeliveryController;
use App\Http\Controllers\Api\V1\Manager\ManagerStaffController;
use App\Http\Controllers\Api\V1\Manager\ManagerDashboardController;
use App\Http\Controllers\Api\V1\Manager\ManagerPackagesController;
use App\Http\Controllers\Api\V1\Distributor\DeliveryController as DistributorDeliveryController;
use App\Http\Controllers\Api\V1\Distributor\DashboardController as DistributorDashboardController;
use App\Http\Controllers\Api\V1\Distributor\BranchController as DistributorBranchController;
use App\Http\Controllers\Api\V1\Distributor\RewardsController as DistributorRewardsController;
use App\Http\Controllers\Api\V1\Rider\RiderDashboardController;
use App\Http\Controllers\Api\V1\Rider\RiderDeliveryController;
use App\Http\Controllers\Api\V1\Rider\RiderEarningsController;

Route::prefix('v1')->group(function () {

    // ─── Diagnostics ──────────────────────────────────────────
    Route::get('/health', [HealthController::class, 'index']);

    // ─── Authentication ───────────────────────────────────────
    Route::prefix('auth')->group(function () {

        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login');

        Route::post('/register/distributor', [AuthController::class, 'registerDistributor'])
            ->middleware('throttle:register');

        Route::post('/refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:refresh');

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

            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::get('finance/summary', [FinanceController::class, 'summary']);

            Route::apiResource('branches', BranchController::class)
                ->only(['index', 'store', 'show', 'update']);

            Route::post('branches/{branch}/assign-manager', [BranchManagerController::class, 'assign']);
            Route::delete('branches/{branch}/assign-manager', [BranchManagerController::class, 'unassign']);
            Route::get('branches/{branch}/managers', [BranchManagerController::class, 'index']);

            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::post('users/{user}/approve', [UserController::class, 'approve']);
            Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);

            Route::get('settings', [SettingsController::class, 'index']);
            Route::put('settings', [SettingsController::class, 'bulkUpdate']);
            Route::get('settings/{key}', [SettingsController::class, 'show'])
                ->where('key', '[A-Za-z0-9._-]+');
            Route::put('settings/{key}', [SettingsController::class, 'update'])
                ->where('key', '[A-Za-z0-9._-]+');

            Route::get('pricing-config', [PricingConfigController::class, 'show']);
            Route::put('pricing-config', [PricingConfigController::class, 'update']);
        });

    // ─── Manager (Phase 6) ────────────────────────────────────
    Route::middleware(['auth:api', 'role:manager'])
        ->prefix('manager')
        ->group(function () {
            Route::get('branch', [ManagerBranchController::class, 'show']);
            Route::put('branch', [ManagerBranchController::class, 'update']);

            Route::get('staff', [ManagerStaffController::class, 'index']);
            Route::post('staff', [ManagerStaffController::class, 'store']);
            Route::get('staff/{user}', [ManagerStaffController::class, 'show']);
            Route::put('staff/{user}', [ManagerStaffController::class, 'update']);
            Route::patch('staff/{user}/status', [ManagerStaffController::class, 'updateStatus']);
            Route::post('staff/{user}/reset-password', [ManagerStaffController::class, 'resetPassword']);

            Route::get('deliveries', [ManagerDeliveryController::class, 'index']);
            Route::get('deliveries/{delivery}', [ManagerDeliveryController::class, 'show']);

            Route::get('dashboard', [ManagerDashboardController::class, 'index']);
            Route::get('packages', [ManagerPackagesController::class, 'index']);
        });

    // ─── Distributor (Phase 7) ────────────────────────────────
    Route::middleware(['auth:api', 'role:distributor'])
        ->prefix('distributor')
        ->group(function () {
            Route::post('deliveries/price-preview', [DistributorDeliveryController::class, 'pricePreview']);
            Route::get('deliveries', [DistributorDeliveryController::class, 'index']);
            Route::post('deliveries', [DistributorDeliveryController::class, 'store']);
            Route::get('deliveries/{delivery}', [DistributorDeliveryController::class, 'show']);
            Route::get('dashboard', [DistributorDashboardController::class, 'index']);
            Route::get('branches', [DistributorBranchController::class, 'index']);
        });

    Route::middleware(['auth:api', 'role:distributor'])
        ->get('/rewards/me', [DistributorRewardsController::class, 'show']);

    // ─── Rider (Phase 8) ──────────────────────────────────────
    Route::middleware(['auth:api', 'role:rider'])
        ->prefix('rider')
        ->group(function () {
            Route::get('dashboard', [RiderDashboardController::class, 'index']);
            Route::get('earnings', [RiderEarningsController::class, 'index']);

            // Deliveries — list/show first, then per-delivery actions.
            Route::get('deliveries', [RiderDeliveryController::class, 'index']);
            Route::get('deliveries/{delivery}', [RiderDeliveryController::class, 'show']);

            Route::post('deliveries/{delivery}/accept', [RiderDeliveryController::class, 'accept']);
            Route::post('deliveries/{delivery}/pickup', [RiderDeliveryController::class, 'pickup']);
            Route::post('deliveries/{delivery}/deliver', [RiderDeliveryController::class, 'deliver']);
        });
});