<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

/**
 * Uniform JSON response envelope used by every ODS API controller.
 *
 * Envelope shapes (must match lib/core/network/api_interceptors.dart):
 *
 *   Success: { "success": true,  "message": "...", "data":    {...}|null }
 *   Failure: { "success": false, "message": "...", "errors":  {...}|null }
 *
 * Controllers extend the base Controller and `use ApiResponse;` to
 * call $this->success(...) / $this->error(...).
 */
trait ApiResponse
{
    protected function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    protected function error(
        string $message = 'Error',
        mixed $errors = null,
        int $status = 400,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $status);
    }
}