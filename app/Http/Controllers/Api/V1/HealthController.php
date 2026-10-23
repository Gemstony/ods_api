<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `GET /api/v1/health` — unauthenticated health probe.
 *
 * Purpose: verifies the full stack end-to-end during development. The
 * Flutter app's login screen calls this via the "Test API connection"
 * button and prints the returned `env` and `database` values in a snackbar.
 *
 * Returns `200 OK` even if the database is unreachable — the difference
 * between "server up, db down" and "server unreachable" matters. The
 * `database` field reports `ok` or `fail`.
 */
class HealthController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $database = 'ok';
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');
        } catch (Throwable $e) {
            $database = 'fail';
        }

        return $this->success(
            data: [
                'app'      => config('app.name', 'ODS API'),
                'version'  => 'v1',
                'env'      => config('app.env', 'unknown'),
                'database' => $database,
                'time'     => now()->toIso8601String(),
            ],
            message: 'ODS API is healthy',
        );
    }
}