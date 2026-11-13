<?php

namespace App\Http\Controllers\Api\V1\Manager;

use App\Http\Controllers\Controller;
use App\Services\Assignment\AssignmentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manager-triggered manual assignment (Phase 10.2).
 *
 * Endpoint:
 *   POST /manager/assignment/run
 *
 * Runs the FIFO queue assignment against the manager's own branch.
 * Useful as a safety valve if the scheduled job is delayed or if a
 * manager sees batched deliveries sitting longer than expected.
 */
class ManagerAssignmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AssignmentService $assignmentService) {}

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

        $result = $this->assignmentService->assignBatchedDeliveries(
            branchId: (int) $branchId,
        );

        return $this->success(
            data: $result->toArray(),
            message: $result->skipped
                ? ($result->skipReason ?? 'Assignment skipped.')
                : 'Assignment run completed.',
        );
    }
}
