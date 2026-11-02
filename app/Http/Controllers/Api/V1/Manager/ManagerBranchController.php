<?php

namespace App\Http\Controllers\Api\V1\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Manager\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Services\Audit\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manager's own-branch operations.
 *
 * Endpoints:
 *   GET /manager/branch  → own branch details
 *   PUT /manager/branch  → update own branch (name / address / GPS / hours)
 *
 * Manager cannot change branch code or status — those are Owner-level
 * decisions (Phase 5.1).
 */
class ManagerBranchController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    // ─── Show ─────────────────────────────────────────────────
    public function show(Request $request): JsonResponse
    {
        $branch = $this->resolveOwnBranch($request);
        if ($branch instanceof JsonResponse) return $branch;

        $branch->load(['managerAssignments.user']);

        return $this->success(new BranchResource($branch), 'OK');
    }

    // ─── Update ───────────────────────────────────────────────
    public function update(
        UpdateBranchRequest $request,
    ): JsonResponse {
        $branch = $this->resolveOwnBranch($request);
        if ($branch instanceof JsonResponse) return $branch;

        $old = [
            'name' => $branch->name,
            'address' => $branch->address,
            'latitude' => $branch->latitude,
            'longitude' => $branch->longitude,
        ];

        $branch->update([
            'name' => $request->input('name'),
            'address' => $request->input('address'),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'operating_hours' => $request->input('operating_hours'),
        ]);

        $branch->refresh();

        $this->auditLogger->logCurrentUser(
            action: 'branch.updated_by_manager',
            description: "Updated {$branch->name}'s details",
            subject: $branch,
            changes: [
                'old' => $old,
                'new' => [
                    'name' => $branch->name,
                    'address' => $branch->address,
                    'latitude' => $branch->latitude,
                    'longitude' => $branch->longitude,
                ],
            ],
        );

        $branch->load(['managerAssignments.user']);

        return $this->success(
            new BranchResource($branch),
            'Branch updated.',
        );
    }

    // ─── Helpers ──────────────────────────────────────────────
    private function resolveOwnBranch(Request $request): Branch|JsonResponse
    {
        $branchId = $request->user()->branch_id;
        if ($branchId === null) {
            return $this->error(
                'Your account is not assigned to a branch.',
                null,
                403,
            );
        }
        $branch = Branch::find($branchId);
        if (! $branch) {
            return $this->error(
                'Your branch could not be found.',
                null,
                404,
            );
        }
        return $branch;
    }
}