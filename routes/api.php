<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Distributor\BranchController as DistributorBranchController;
use App\Http\Controllers\Api\V1\Distributor\DashboardController as DistributorDashboardController;
use App\Http\Controllers\Api\V1\Distributor\DeliveryController as DistributorDeliveryController;
use App\Http\Controllers\Api\V1\Distributor\DisputeController as DistributorDisputeController;
use App\Http\Controllers\Api\V1\Distributor\PaymentController as DistributorPaymentController;
use App\Http\Controllers\Api\V1\Distributor\RewardsController as DistributorRewardsController;
use App\Http\Controllers\Api\V1\Distributor\VerificationController as DistributorVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Manager\DisputeController as ManagerDisputeController;
use App\Http\Controllers\Api\V1\Manager\ManagerAssignmentController;
use App\Http\Controllers\Api\V1\Manager\ManagerBatchingController;
use App\Http\Controllers\Api\V1\Manager\ManagerBranchController;
use App\Http\Controllers\Api\V1\Manager\ManagerDashboardController;
use App\Http\Controllers\Api\V1\Manager\ManagerDeliveryController;
use App\Http\Controllers\Api\V1\Manager\ManagerPackagesController;
use App\Http\Controllers\Api\V1\Manager\ManagerStaffController;
use App\Http\Controllers\Api\V1\Manager\ManualPaymentController as ManagerManualPaymentController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Owner\BranchController;
use App\Http\Controllers\Api\V1\Owner\BranchManagerController;
use App\Http\Controllers\Api\V1\Owner\DashboardController;
use App\Http\Controllers\Api\V1\Owner\DisputeController as OwnerDisputeController;
use App\Http\Controllers\Api\V1\Owner\EarningLedgerController;
use App\Http\Controllers\Api\V1\Owner\FinanceController;
use App\Http\Controllers\Api\V1\Owner\ManualPaymentController as OwnerManualPaymentController;
use App\Http\Controllers\Api\V1\Owner\PaymentLedgerController;
use App\Http\Controllers\Api\V1\Owner\PayoutLedgerController;
use App\Http\Controllers\Api\V1\Owner\PricingConfigController;
use App\Http\Controllers\Api\V1\Owner\ReconciliationController;
use App\Http\Controllers\Api\V1\Owner\SettingsController;
use App\Http\Controllers\Api\V1\Owner\UserController;
use App\Http\Controllers\Api\V1\Public\PublicSettingsController;
use App\Http\Controllers\Api\V1\Receiver\ReceiverPackageController;
use App\Http\Controllers\Api\V1\Receiver\RegionalDispatcherController;
use App\Http\Controllers\Api\V1\Rider\DisputeController as RiderDisputeController;
use App\Http\Controllers\Api\V1\Rider\RiderAvailabilityController;
use App\Http\Controllers\Api\V1\Rider\RiderDashboardController;
use App\Http\Controllers\Api\V1\Rider\RiderDeliveryController;
use App\Http\Controllers\Api\V1\Rider\RiderEarningsController;
use App\Http\Controllers\Api\V1\Webhooks\ClickPesaWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::get('/health', [HealthController::class, 'index']);

    Route::post('/webhooks/clickpesa', [ClickPesaWebhookController::class, 'receive'])
        ->middleware('throttle:60,1');

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

    Route::get('/settings/public', [PublicSettingsController::class, 'index'])
        ->middleware('throttle:60,1');

    // ─── Notifications (Phase 13.1) ──────────────────────────
    Route::middleware('auth:api')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::patch('/notifications/{notification}/dismiss', [NotificationController::class, 'dismiss']);
    });

    // ─── Owner (Phase 5 + 11.4 + 13.4 + 13.7) ────────────────
    Route::middleware(['auth:api', 'role:owner'])
        ->prefix('owner')
        ->group(function () {
            // Core
            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::get('finance/summary', [FinanceController::class, 'summary']);

            // Branches
            Route::apiResource('branches', BranchController::class)
                ->only(['index', 'store', 'show', 'update']);
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

            // Settings + Pricing config
            Route::get('settings', [SettingsController::class, 'index']);
            Route::put('settings', [SettingsController::class, 'bulkUpdate']);
            Route::get('settings/{key}', [SettingsController::class, 'show'])
                ->where('key', '[A-Za-z0-9._-]+');
            Route::put('settings/{key}', [SettingsController::class, 'update'])
                ->where('key', '[A-Za-z0-9._-]+');
            Route::get('pricing-config', [PricingConfigController::class, 'show']);
            Route::put('pricing-config', [PricingConfigController::class, 'update']);

            // ─── Ledger (Phase 11.4) ─────────────────────────
            // `payments/pending` MUST come before `payments/{payment}`
            // or Laravel matches 'pending' as an ID and returns 404.
            Route::get('payments', [PaymentLedgerController::class, 'index']);
            Route::get('payments/pending', [OwnerManualPaymentController::class, 'index']);
            Route::post('payments/{payment}/approve', [OwnerManualPaymentController::class, 'approve']);
            Route::post('payments/{payment}/reject', [OwnerManualPaymentController::class, 'reject']);
            Route::get('payments/{payment}', [PaymentLedgerController::class, 'show']);

            Route::get('earnings', [EarningLedgerController::class, 'index']);
            Route::get('earnings/{earning}', [EarningLedgerController::class, 'show']);
            Route::get('payouts', [PayoutLedgerController::class, 'index']);
            Route::get('payouts/{payout}', [PayoutLedgerController::class, 'show']);

            Route::post('ledger/reconcile', [ReconciliationController::class, 'reconcile']);
            Route::get('ledger/reconcile/last', [ReconciliationController::class, 'last']);

            // ─── Disputes (Phase 13.4) ───────────────────────
            Route::get('disputes', [OwnerDisputeController::class, 'index']);
            Route::get('disputes/{dispute}', [OwnerDisputeController::class, 'show']);
            Route::post('disputes/{dispute}/review', [OwnerDisputeController::class, 'startReview']);
            Route::post('disputes/{dispute}/resolve', [OwnerDisputeController::class, 'resolve']);
            Route::post('disputes/{dispute}/close', [OwnerDisputeController::class, 'close']);
            Route::post('disputes/{dispute}/escalate', [OwnerDisputeController::class, 'escalate']);
        });

    // ─── Manager (Phase 6 + 13.4 + 13.7) ─────────────────────
    Route::middleware(['auth:api', 'role:manager'])
        ->prefix('manager')
        ->group(function () {
            Route::get('branch', [ManagerBranchController::class, 'show']);
            Route::put('branch', [ManagerBranchController::class, 'update']);

            // Staff
            Route::get('staff', [ManagerStaffController::class, 'index']);
            Route::post('staff', [ManagerStaffController::class, 'store']);
            Route::get('staff/{user}', [ManagerStaffController::class, 'show']);
            Route::put('staff/{user}', [ManagerStaffController::class, 'update']);
            Route::patch('staff/{user}/status', [ManagerStaffController::class, 'updateStatus']);
            Route::post('staff/{user}/reset-password', [ManagerStaffController::class, 'resetPassword']);

            // Deliveries + Packages
            Route::get('deliveries', [ManagerDeliveryController::class, 'index']);
            Route::get('deliveries/{delivery}', [ManagerDeliveryController::class, 'show']);
            Route::get('dashboard', [ManagerDashboardController::class, 'index']);
            Route::get('packages', [ManagerPackagesController::class, 'index']);

            // Orchestration
            Route::post('batching/run', [ManagerBatchingController::class, 'run']);
            Route::post('assignment/run', [ManagerAssignmentController::class, 'run']);
            Route::post('assignment/release-expired', [ManagerAssignmentController::class, 'releaseExpired']);

            // ─── Manual payments (Phase 13.7) ───────────────
            // No `payments/{payment}` GET route here, so ordering
            // doesn't matter — but keeping `pending` first for symmetry.
            Route::get('payments/pending', [ManagerManualPaymentController::class, 'index']);
            Route::post('payments/{payment}/approve', [ManagerManualPaymentController::class, 'approve']);
            Route::post('payments/{payment}/reject', [ManagerManualPaymentController::class, 'reject']);

            // ─── Disputes (Phase 13.4) ──────────────────────
            Route::get('disputes', [ManagerDisputeController::class, 'index']);
            Route::post('disputes', [ManagerDisputeController::class, 'store']);
            Route::get('disputes/{dispute}', [ManagerDisputeController::class, 'show']);
        });

    // ─── Distributor (Phase 7 + 11.2 + 12.3 + 13.4 + 13.7) ───
    Route::middleware(['auth:api', 'role:distributor'])
        ->prefix('distributor')
        ->group(function () {
            Route::post('deliveries/price-preview', [DistributorDeliveryController::class, 'pricePreview']);
            Route::get('deliveries', [DistributorDeliveryController::class, 'index']);
            Route::post('deliveries', [DistributorDeliveryController::class, 'store']);
            Route::get('deliveries/{delivery}', [DistributorDeliveryController::class, 'show']);
            Route::get('dashboard', [DistributorDashboardController::class, 'index']);
            Route::get('branches', [DistributorBranchController::class, 'index']);

            // Payments
            Route::post('payments/initiate', [DistributorPaymentController::class, 'initiate']);
            Route::post('payments/manual', [DistributorPaymentController::class, 'manual']);
            Route::get('payments/{payment}', [DistributorPaymentController::class, 'show']);
            Route::post('payments/{payment}/refresh', [DistributorPaymentController::class, 'refresh']);

            // Verification
            Route::post(
                'deliveries/{delivery}/verification/issue',
                [DistributorVerificationController::class, 'issue'],
            )->middleware('throttle:10,1');

            // Disputes
            Route::get('disputes', [DistributorDisputeController::class, 'index']);
            Route::post('disputes', [DistributorDisputeController::class, 'store']);
            Route::get('disputes/{dispute}', [DistributorDisputeController::class, 'show']);
        });

    Route::middleware(['auth:api', 'role:distributor'])
        ->get('/rewards/me', [DistributorRewardsController::class, 'show']);

    // ─── Rider (Phase 8 + 13.4) ──────────────────────────────
    Route::middleware(['auth:api', 'role:rider'])
        ->prefix('rider')
        ->group(function () {
            Route::get('dashboard', [RiderDashboardController::class, 'index']);
            Route::get('earnings', [RiderEarningsController::class, 'index']);
            Route::get('deliveries', [RiderDeliveryController::class, 'index']);
            Route::get('deliveries/{delivery}', [RiderDeliveryController::class, 'show']);
            Route::post('deliveries/{delivery}/accept', [RiderDeliveryController::class, 'accept']);
            Route::post('deliveries/{delivery}/pickup', [RiderDeliveryController::class, 'pickup']);
            Route::post('deliveries/{delivery}/deliver', [RiderDeliveryController::class, 'deliver']);
            Route::get('queue-status', [RiderAvailabilityController::class, 'show']);
            Route::patch('availability', [RiderAvailabilityController::class, 'update']);

            // Disputes
            Route::get('disputes', [RiderDisputeController::class, 'index']);
            Route::post('disputes', [RiderDisputeController::class, 'store']);
            Route::get('disputes/{dispute}', [RiderDisputeController::class, 'show']);
        });

    // ─── Receiver (Phase 9) ──────────────────────────────────
    Route::middleware(['auth:api', 'role:receiver'])
        ->prefix('receiver')
        ->group(function () {
            Route::get('packages', [ReceiverPackageController::class, 'index']);
            Route::get('packages/{delivery}', [ReceiverPackageController::class, 'show']);
            Route::post('packages/{delivery}/verify', [ReceiverPackageController::class, 'verify']);
            Route::post('packages/{delivery}/release', [ReceiverPackageController::class, 'release']);

            Route::get('regional', [RegionalDispatcherController::class, 'index']);
            Route::get('regional/{delivery}', [RegionalDispatcherController::class, 'show']);
            Route::post('regional/{delivery}/assign', [RegionalDispatcherController::class, 'assign']);
            Route::post('regional/{delivery}/dispatch', [RegionalDispatcherController::class, 'dispatch']);
            Route::post('regional/{delivery}/confirm-arrival', [RegionalDispatcherController::class, 'confirmArrival']);
            Route::get('regional/{delivery}/ticket', [RegionalDispatcherController::class, 'ticket']);
        });
});
