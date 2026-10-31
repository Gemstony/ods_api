<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Owner\ChangeUserStatusRequest;
use App\Http\Requests\Api\V1\Owner\ResetUserPasswordRequest;
use App\Http\Requests\Api\V1\Owner\StoreUserRequest;
use App\Http\Requests\Api\V1\Owner\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Owner-facing user management.
 *
 * Endpoints:
 *   GET    /owner/users                       → index (paginated + filters)
 *   GET    /owner/users/{user}                → show
 *   POST   /owner/users                       → store (Manager/Receiver/Rider)
 *   PUT    /owner/users/{user}                → update basic info
 *   POST   /owner/users/{user}/approve        → approve a pending account
 *   PATCH  /owner/users/{user}/status         → suspend / activate / deactivate
 *   POST   /owner/users/{user}/reset-password → set a new password
 *
 * Cannot: create Distributor (self-registers), create Owner (singleton),
 * change email or role (dedicated flows if ever needed).
 */
class UserController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    // ─── index ────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['role.permissions', 'branch']);

        if ($request->filled('role')) {
            $roleName = $request->query('role');
            $query->whereHas('role', fn ($q) => $q->where('name', $roleName));
        }

        if ($request->filled('status')) {
            $status = $request->query('status');
            if (in_array($status, [
                User::STATUS_PENDING,
                User::STATUS_ACTIVE,
                User::STATUS_SUSPENDED,
                User::STATUS_DEACTIVATED,
            ], true)) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->query('branch_id'));
        }

        if ($request->boolean('pending_only')) {
            $query->where('status', User::STATUS_PENDING);
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
    public function show(User $user): JsonResponse
    {
        $user->load(['role.permissions', 'branch']);
        return $this->success(new UserResource($user), 'OK');
    }

    // ─── store ────────────────────────────────────────────────
    public function store(StoreUserRequest $request): JsonResponse
    {
        $roleName = $request->input('role');
        $role = Role::where('name', $roleName)->firstOrFail();

        $user = DB::transaction(function () use ($request, $role, $roleName) {
            $data = [
                'role_id' => $role->id,
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'phone' => $request->input('phone'),
                'password' => $request->input('password'),
                'status' => User::STATUS_ACTIVE,
            ];

            if ($roleName === 'receiver') {
                $data['branch_id'] = (int) $request->input('branch_id');
                $data['dispatcher_enabled'] = $request->boolean('dispatcher_enabled', false);
            } elseif ($roleName === 'rider') {
                $data['branch_id'] = (int) $request->input('branch_id');
                $data['nida'] = $request->input('nida');
                $data['sex'] = $request->input('sex');
                $data['date_of_birth'] = $request->input('date_of_birth');
                $data['vehicle_type'] = $request->input('vehicle_type');
                $data['vehicle_color'] = $request->input('vehicle_color');
                $data['vehicle_ownership'] = $request->input('vehicle_ownership');
                $data['plate_number'] = $request->input('plate_number');
            }
            // Manager: branch_id handled by Branch::assignManager() below.

            $user = User::create($data);

            if ($roleName === 'manager') {
                $branch = Branch::findOrFail($request->input('branch_id'));
                $branch->assignManager($user, $request->user());
            }

            return $user;
        });

        $this->auditLogger->logUserCreated($request->user(), $user);

        $user->load(['role.permissions', 'branch']);
        return $this->success(
            data: new UserResource($user),
            message: 'User created.',
            status: 201,
        );
    }

    // ─── update ───────────────────────────────────────────────
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        // Prevent branch reassignment for Manager via this endpoint.
        // Use the branch-manager assignment endpoint so history is preserved.
        if ($request->has('branch_id') &&
            (int) $request->input('branch_id') !== (int) $user->branch_id &&
            $user->hasRole(Role::MANAGER)) {
            return $this->error(
                'Use POST /owner/branches/{branch}/assign-manager to change a Manager\'s branch.',
                null,
                422,
            );
        }

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
            $updates['branch_id'] = $request->input('branch_id');
        } elseif ($user->hasRole(Role::RECEIVER)) {
            $updates['branch_id'] = $request->input('branch_id');
            if ($request->has('dispatcher_enabled')) {
                $updates['dispatcher_enabled'] = $request->boolean('dispatcher_enabled');
            }
        }

        $user->update($updates);

        $this->auditLogger->logCurrentUser(
            action: 'user.updated',
            description: "Updated {$user->name}'s profile",
            subject: $user,
        );

        $user->load(['role.permissions', 'branch']);
        return $this->success(new UserResource($user), 'User updated.');
    }

    // ─── approve ──────────────────────────────────────────────
    public function approve(Request $request, User $user): JsonResponse
    {
        if ($user->status !== User::STATUS_PENDING) {
            return $this->error(
                'Only pending accounts can be approved.',
                null,
                422,
            );
        }

        $user->update(['status' => User::STATUS_ACTIVE]);

        $this->auditLogger->logCurrentUser(
            action: 'user.approved',
            description: "Approved {$user->name} ({$user->role?->name})",
            subject: $user,
        );

        $user->load(['role.permissions', 'branch']);
        return $this->success(
            new UserResource($user),
            'Account approved.',
        );
    }

    // ─── updateStatus ─────────────────────────────────────────
    public function updateStatus(
        ChangeUserStatusRequest $request,
        User $user,
    ): JsonResponse {
        $newStatus = $request->input('status');
        $actor = $request->user();

        // Guard rails
        if ($user->status === User::STATUS_PENDING) {
            return $this->error(
                'Pending accounts must be approved, not activated.',
                null,
                422,
            );
        }
        if ($user->id === $actor->id) {
            return $this->error(
                'You cannot change your own account status.',
                null,
                403,
            );
        }
        if ($user->hasRole(Role::OWNER)) {
            return $this->error(
                "The Owner's status cannot be changed.",
                null,
                403,
            );
        }

        $oldStatus = $user->status;
        $user->update(['status' => $newStatus]);

        // Revoke all active sessions on suspension / deactivation.
        if (in_array($newStatus, [User::STATUS_SUSPENDED, User::STATUS_DEACTIVATED], true)) {
            $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        $this->auditLogger->logCurrentUser(
            action: 'user.status_changed',
            description: "Changed {$user->name}'s status from {$oldStatus} to {$newStatus}",
            subject: $user,
            changes: ['status' => ['from' => $oldStatus, 'to' => $newStatus]],
        );

        $user->load(['role.permissions', 'branch']);
        return $this->success(new UserResource($user), 'Status updated.');
    }

    // ─── resetPassword ────────────────────────────────────────
    public function resetPassword(
        ResetUserPasswordRequest $request,
        User $user,
    ): JsonResponse {
        // Owner cannot reset another Owner's password (defensive).
        if ($user->hasRole(Role::OWNER) && $user->id !== $request->user()->id) {
            return $this->error(
                "The Owner's password cannot be reset via this endpoint.",
                null,
                403,
            );
        }

        $user->update(['password' => $request->input('password')]);

        // Revoke all sessions — old tokens must not survive a password change.
        $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $this->auditLogger->logCurrentUser(
            action: 'user.password_reset',
            description: "Reset {$user->name}'s password",
            subject: $user,
        );

        return $this->success(
            null,
            'Password reset. Share the new password with the user securely.',
        );
    }
}