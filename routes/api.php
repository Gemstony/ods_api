<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ODS API — v1
|--------------------------------------------------------------------------
|
| Laravel 11 applies the `/api` prefix automatically (wired by
| install:api in bootstrap/app.php). Adding Route::prefix('v1') here
| means every path resolves to `/api/v1/...`, matching the Flutter
| app's AppConfig.apiBaseUrl.
|
| Populated incrementally:
|   1.10 → GET /health                          ✅ (this batch)
|   Phase 3  → /auth/*                          (login, refresh, me)
|   Phase 5  → /owner/*                         (branches, users, settings)
|   Phase 6  → /manager/*                       (staff, deliveries)
|   Phase 7  → /distributor/*                   (delivery requests)
|   Phase 8  → /rider/*                         (tasks, earnings)
|   Phase 9  → /receiver/*                      (packages, dispatch)
|   Phase 11 → /payments/*, /webhooks/*         (ClickPesa)
|
*/

Route::prefix('v1')->group(function () {
    // Diagnostics — used by the Flutter app's login screen to verify the
    // network pipeline is wired end-to-end.
    Route::get('/health', [HealthController::class, 'index']);
});