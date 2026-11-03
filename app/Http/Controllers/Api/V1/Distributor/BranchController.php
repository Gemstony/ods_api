<?php

namespace App\Http\Controllers\Api\V1\Distributor;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Distributor-facing branch list — for the pickup-branch picker.
 *
 * Endpoint:
 *   GET /distributor/branches  → all ACTIVE branches
 *
 * Only active branches appear (inactive branches can't receive pickups).
 * Ordered by name, alphabetical. No pagination — the number of branches
 * in a real deployment is dozens, not thousands.
 *
 * This endpoint exists because `/owner/branches` (5.1) is `role:owner`
 * — Distributors need their own auth-scoped read.
 */
class BranchController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $branches = Branch::query()
            ->where('status', Branch::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();

        $items = [];
        foreach ($branches as $branch) {
            $items[] = (new BranchResource($branch))->resolve();
        }

        return $this->success(
            data: [
                'data' => $items,
                'total' => count($items),
            ],
            message: 'OK',
        );
    }
}