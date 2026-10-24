<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role-gating middleware with built-in active-status check.
 *
 * Usage in routes:
 *   ->middleware('role:owner')                        Owner only
 *   ->middleware('role:owner,manager')                Owner OR Manager
 *   ->middleware('role:manager,receiver')             Manager OR Receiver
 *
 * Behaviour:
 *   - Not authenticated          → 401 Unauthenticated
 *   - Authenticated but inactive → 403 with status-specific message
 *   - Authenticated with wrong   → 403 with role-list message
 *     role
 *   - Otherwise                  → request passes
 *
 * This middleware subsumes "is this user active?" — a suspended user with
 * a valid token issued before suspension is rejected immediately on their
 * next gated request.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->error('Authentication required.', 401);
        }

        if (! $user->isActive()) {
            $message = match ($user->status) {
                User::STATUS_PENDING     => 'Your account is pending approval. Please wait for the administrator.',
                User::STATUS_SUSPENDED   => 'Your account has been suspended. Contact the administrator.',
                User::STATUS_DEACTIVATED => 'Your account has been deactivated.',
                default                  => 'Your account is not active.',
            };
            return $this->error($message, 403);
        }

        $userRole = $user->role?->name;

        if ($userRole === null || ! in_array($userRole, $allowedRoles, true)) {
            return $this->error(
                sprintf(
                    'You do not have access to this resource. Required role: %s. Current role: %s.',
                    implode(' or ', $allowedRoles),
                    $userRole ?? 'none',
                ),
                403,
            );
        }

        return $next($request);
    }

    protected function error(string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}