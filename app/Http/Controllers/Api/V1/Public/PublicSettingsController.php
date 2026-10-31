<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public settings — readable without authentication.
 *
 * Endpoint:
 *   GET /settings/public
 *
 * Returns only settings with `is_public = true`. Used by the Flutter app
 * BEFORE login for:
 *   - `app.min_supported_version` — block outdated clients
 *   - `app.maintenance_mode`       — show a maintenance screen
 *   - `app.app_name`               — brand display
 *
 * Any other public settings are included too (this endpoint is generic).
 */
class PublicSettingsController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $rows = Setting::query()
            ->where('is_public', true)
            ->orderBy('key')
            ->get()
            ->map(fn ($s) => [
                'key' => $s->key,
                'value' => json_decode($s->value, true),
            ])
            ->all();

        return $this->success($rows, 'OK');
    }
}