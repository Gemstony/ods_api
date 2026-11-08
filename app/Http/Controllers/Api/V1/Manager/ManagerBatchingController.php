<?php

namespace App\Http\Controllers\Api\V1\Manager;

use App\Http\Controllers\Controller;
use App\Services\Batching\BatchingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manager-triggered manual batching (Phase 10.1).
 *
 * Endpoint:
 *   POST /manager/batching/run
 *
 * Runs the batching service against the manager's own branch. Useful as
 * a safety valve if the scheduled job (Phase 10.3) is delayed or if a
 * manager needs to force the queue forward.
 *
 * Send deliveries are never touched by a branch-scoped run (they have
 * no `pickup_branch_id`); sends are batched only by the global scheduled
 * job.
 */
class ManagerBatchingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BatchingService $batchingService) {}

    public function run(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;
        if ($branchId === null) {
            return $this->error(
                'Your account is not assigned to a branch.',
                null,
                403,
            );
        }

        $result = $this->batchingService->batchPendingDeliveries(
            branchId: (int) $branchId,
        );

        return $this->success(
            data: $result->toArray(),
            message: $result->skipped
                ? ($result->skipReason ?? 'Batching skipped.')
                : 'Batching run completed.',
        );
    }
}