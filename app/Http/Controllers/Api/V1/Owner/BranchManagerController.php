<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Owner\AssignBranchManagerRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner-facing branch-manager assignment.
 *
 * Endpoints:
 *   POST   /owner/branches/{branch}/assign-manager   → assign user_id
 *   DELETE /owner/branches/{branch}/assign-manager   → unassign current
 *   GET    /owner/branches/{branch}/managers         → history
 *
 * All mutations call Branch::assignManager() / Branch::unassignManager()
 * from Phase 2.2, which handle the transactional close-old/open-new
 * logic and keep `users.branch_id` in sync with the current manager.
 */
class BranchManagerController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    // ─── assign ───────────────────────────────────────────────
    public function assign(
        AssignBranchManagerRequest $request,
        Branch $branch,
    ): JsonResponse {
        $user = User::with('role')->findOrFail($request->input('user_id'));

        if (! $user->hasRole(Role::MANAGER)) {
            return $this->error(
                'Only users with the Manager role can be assigned as branch manager.',
                null,
                422,
            );
        }
        if (! $user->isActive()) {
            return $this->error(
                'Cannot assign an inactive user as branch manager.',
                null,
                422,
            );
        }

        $branch->assignManager($user, $request->user());

        $this->auditLogger->logBranchAssignment(
            $request->user(),
            $branch,
            $user,
        );

        $branch->load(['managerAssignments.user']);
        return $this->success(
            new BranchResource($branch),
            'Manager assigned.',
        );
    }

    // ─── unassign ─────────────────────────────────────────────
    public function unassign(Request $request, Branch $branch): JsonResponse
    {
        if ($branch->currentManager() === null) {
            return $this->error(
                'This branch has no assigned manager.',
                null,
                422,
            );
        }

        $branch->unassignManager($request->user());

        $this->auditLogger->logCurrentUser(
            action: 'branch.manager_unassigned',
            description: "Unassigned manager from {$branch->name}",
            subject: $branch,
        );

        $branch->load(['managerAssignments.user']);
        return $this->success(
            new BranchResource($branch),
            'Manager unassigned.',
        );
    }

    // ─── index (history) ──────────────────────────────────────
    public function index(Branch $branch): JsonResponse
    {
        $assignments = $branch->managerAssignments()
            ->with(['user', 'assigner', 'unassigner'])
            ->orderByDesc('assigned_at')
            ->get();

        $items = $assignments->map(fn ($a) => [
            'id' => $a->id,
            'user' => [
                'id' => $a->user->id,
                'name' => $a->user->name,
                'email' => $a->user->email,
            ],
            'assigned_at' => $a->assigned_at?->toIso8601String(),
            'assigned_by' => $a->assigner ? [
                'id' => $a->assigner->id,
                'name' => $a->assigner->name,
            ] : null,
            'unassigned_at' => $a->unassigned_at?->toIso8601String(),
            'unassigned_by' => $a->unassigner ? [
                'id' => $a->unassigner->id,
                'name' => $a->unassigner->name,
            ] : null,
            'is_active' => $a->isActive(),
        ])->all();

        return $this->success($items, 'OK');
    }
}