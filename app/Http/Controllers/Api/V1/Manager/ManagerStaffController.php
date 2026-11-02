<?php

namespace App\Http\Controllers\Api\V1\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Manager\ChangeStaffStatusRequest;
use App\Http\Requests\Api\V1\Manager\StoreStaffRequest;
use App\Http\Requests\Api\V1\Manager\UpdateStaffRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manager's staff management for their own branch.
 *
 * Endpoints:
 *   GET    /manager/staff                    → list (paginated, filters)
 *   POST   /manager/staff                    → create Receiver or Rider
 *   GET    /manager/staff/{user}             → one staff member
 *   PUT    /manager/staff/{user}             → update basic info
 *   PATCH  /manager/staff/{user}/status      → suspend / activate / deactivate
 *   POST   /manager/staff/{user}/reset-password
 *
 * Every operation is scoped to the manager's own branch. Cross-branch
 * access returns 404 (not 403) so branch existence isn't leaked.
 *
 * Staff = Receivers and Riders only. Managers and Owners are not
 * visible through this controller — the Owner manages those.
 */
class ManagerStaffController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    // ─── index ────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;
        if ($branchId === null) {
            return $this->error(
                'Your account is not assigned to a branch.',
                null,
                403,
            );
        }

        $query = User::query()
            ->where('branch_id', $branchId)
            ->whereHas('role', fn ($q) => $q->whereIn('name', [Role::RECEIVER, Role::RIDER]))
            ->with(['role.permissions', 'branch']);

        if ($request->filled('role')) {
            $roleName = $request->query('role');
            if (in_array($roleName, [Role::RECEIVER, Role::RIDER], true)) {
                $query->whereHas('role', fn ($q) => $q->where('name', $roleName));
            }
        }

        if ($request->filled('status')) {
            $status = $request->query('status');
            if (in_array($status, [
                User::STATUS_ACTIVE,
                User::STATUS_SUSPENDED,
                User::STATUS_DEACTIVATED,
            ], true)) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        $query->orderBy('name');

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;
        $paginated = $query->paginate($perPage);

        $items = [];
        foreach ($paginated->items() as $user) {
            $items[] = (new UserResource($user))->toArray($request);
        }

        return $this->success(
            data: [
                'data' => $items,
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
            message: 'OK',
        );
    }

    // ─── show ─────────────────────────────────────────────────
    public function show(Request $request, User $user): JsonResponse
    {
        $guard = $this->ensureStaffOfOwnBranch($request, $user);
        if ($guard !== null) return $guard;

        $user->load(['role.permissions', 'branch']);

        return $this->success(new UserResource($user), 'OK');
    }

    // ─── store ────────────────────────────────────────────────
    public function store(StoreStaffRequest $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;
        if ($branchId === null) {
            return $this->error(
                'Your account is not assigned to a branch.',
                null,
                403,
            );
        }

        $roleName = $request->input('role');
        $role = Role::where('name', $roleName)->firstOrFail();

        $data = [
            'role_id' => $role->id,
            'branch_id' => $branchId, // forced — manager can't target another branch
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'password' => $request->input('password'),
            'status' => User::STATUS_ACTIVE,
        ];

        if ($roleName === 'receiver') {
            $data['dispatcher_enabled'] = $request->boolean(
                'dispatcher_enabled',
                false,
            );
        }

        if ($roleName === 'rider') {
            $data['nida'] = $request->input('nida');
            $data['sex'] = $request->input('sex');
            $data['date_of_birth'] = $request->input('date_of_birth');
            $data['vehicle_type'] = $request->input('vehicle_type');
            $data['vehicle_color'] = $request->input('vehicle_color');
            $data['vehicle_ownership'] = $request->input('vehicle_ownership');
            $data['plate_number'] = $request->input('plate_number');
        }

        $user = User::create($data);

        $this->auditLogger->logUserCreated($request->user(), $user);

        $user->load(['role.permissions', 'branch']);

        return $this->success(
            data: new UserResource($user),
            message: 'Staff member created.',
            status: 201,
        );
    }

    // ─── update ───────────────────────────────────────────────
    public function update(
        UpdateStaffRequest $request,
        User $user,
    ): JsonResponse {
        $guard = $this->ensureStaffOfOwnBranch($request, $user);
        if ($guard !== null) return $guard;

        $updates = [
            'name' => $request->input('name'),
            'phone' => $request->input('phone'),
        ];

        if ($user->hasRole(Role::RIDER)) {
            $updates['nida'] = $request->input('nida');
            $updates['sex'] = $request->input('sex');
            $updates['date_of_birth'] = $request->input('date_of_birth');
            $updates['vehicle_type'] = $request->input('vehicle_type');
            $updates['vehicle_color'] = $request->input('vehicle_color');
            $updates['vehicle_ownership'] = $request->input('vehicle_ownership');
            $updates['plate_number'] = $request->input('plate_number');
        } elseif ($user->hasRole(Role::RECEIVER)) {
            if ($request->has('dispatcher_enabled')) {
                $updates['dispatcher_enabled'] = $request->boolean('dispatcher_enabled');
            }
        }

        $user->update($updates);

        $this->auditLogger->logCurrentUser(
            action: 'user.updated_by_manager',
            description: "Updated {$user->name}'s profile",
            subject: $user,
        );

        $user->load(['role.permissions', 'branch']);

        return $this->success(new UserResource($user), 'Staff member updated.');
    }

    // ─── updateStatus ─────────────────────────────────────────
    public function updateStatus(
        ChangeStaffStatusRequest $request,
        User $user,
    ): JsonResponse {
        $guard = $this->ensureStaffOfOwnBranch($request, $user);
        if ($guard !== null) return $guard;

        if ($user->id === $request->user()->id) {
            return $this->error(
                'You cannot change your own account status.',
                null,
                403,
            );
        }

        $newStatus = $request->input('status');
        $oldStatus = $user->status;
        $user->update(['status' => $newStatus]);

        if (in_array($newStatus, [User::STATUS_SUSPENDED, User::STATUS_DEACTIVATED], true)) {
            $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        $this->auditLogger->logCurrentUser(
            action: 'user.status_changed_by_manager',
            description: "Changed {$user->name}'s status from {$oldStatus} to {$newStatus}",
            subject: $user,
            changes: ['status' => ['from' => $oldStatus, 'to' => $newStatus]],
        );

        $user->load(['role.permissions', 'branch']);

        return $this->success(new UserResource($user), 'Status updated.');
    }

    // ─── resetPassword ────────────────────────────────────────
    public function resetPassword(
        Request $request,
        User $user,
    ): JsonResponse {
        $guard = $this->ensureStaffOfOwnBranch($request, $user);
        if ($guard !== null) return $guard;

        $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Passwords do not match.',
        ]);

        $user->update(['password' => $request->input('password')]);
        $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $this->auditLogger->logCurrentUser(
            action: 'user.password_reset_by_manager',
            description: "Reset {$user->name}'s password",
            subject: $user,
        );

        return $this->success(
            null,
            'Password reset. Share the new password with the staff member securely.',
        );
    }

    // ─── Guards ───────────────────────────────────────────────
    /**
     * Returns a JsonResponse if the guard fails, or null if it passes.
     *
     * Rules:
     *   1. Caller must have a branch.
     *   2. Target user must be in the same branch.
     *   3. Target user must be a Receiver or Rider (not a Manager/Owner).
     *
     * Rule 2 & 3 both return 404 to prevent branch/role enumeration.
     */
    private function ensureStaffOfOwnBranch(
        Request $request,
        User $user,
    ): ?JsonResponse {
        $managerBranchId = $request->user()->branch_id;
        if ($managerBranchId === null) {
            return $this->error(
                'Your account is not assigned to a branch.',
                null,
                403,
            );
        }
        if ($user->branch_id !== $managerBranchId) {
            return $this->error('Staff member not found.', null, 404);
        }
        if (! $user->hasRole(Role::RECEIVER) && ! $user->hasRole(Role::RIDER)) {
            return $this->error('Staff member not found.', null, 404);
        }
        return null;
    }
}