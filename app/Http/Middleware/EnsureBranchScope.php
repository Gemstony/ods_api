<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branch-scoping middleware for Manager / Rider / Receiver.
 *
 * Prevents a user bound to Branch A from accessing routes that name
 * Branch B in the route parameter, request body, or X-Branch-Id header.
 *
 * Bypass rules:
 *   - Owner:        operates globally, always passes.
 *   - Distributor:  has no branch; their deliveries are scoped at the
 *                   controller via `where('distributor_id', Auth::id())`.
 *
 * Failure modes for Manager / Rider / Receiver:
 *   - No `branch_id` on their user record       → 403
 *   - Branch named in request ≠ user's branch   → 403
 *
 * Pass-through:
 *   - No branch named anywhere in the request   → passes (controller-level
 *                                                 filtering expected)
 *
 * Branch resolution order:
 *   1. Route parameter named `branch` or `branchId`
 *   2. Request body `branch_id`
 *   3. Request header `X-Branch-Id`
 */
class EnsureBranchScope
{
    private const BRANCH_ROUTE_PARAMS = ['branch', 'branchId'];
    private const BRANCH_INPUT_KEY    = 'branch_id';
    private const BRANCH_HEADER_NAME  = 'X-Branch-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->error('Authentication required.', 403);
        }

        // Owner sees all branches
        if ($user->hasRole(Role::OWNER)) {
            return $next($request);
        }

        // Distributors have no branch binding
        if ($user->hasRole(Role::DISTRIBUTOR)) {
            return $next($request);
        }

        // Manager / Rider / Receiver must be assigned to a branch
        if ($user->branch_id === null) {
            return $this->error(
                'Your account is not assigned to a branch. Please contact your administrator.',
                403,
            );
        }

        $requestedBranch = $this->resolveRequestedBranch($request);

        // No branch named — controller-level filtering expected
        if ($requestedBranch === null) {
            return $next($request);
        }

        if ((int) $requestedBranch !== (int) $user->branch_id) {
            return $this->error(
                'You do not have access to this branch.',
                403,
            );
        }

        return $next($request);
    }

    protected function resolveRequestedBranch(Request $request): ?string
    {
        // 1. Route parameter
        foreach (self::BRANCH_ROUTE_PARAMS as $name) {
            $value = $request->route($name);
            if ($value !== null && (is_int($value) || ctype_digit((string) $value))) {
                return (string) $value;
            }
        }

        // 2. Request body
        $input = $request->input(self::BRANCH_INPUT_KEY);
        if ($input !== null && (is_int($input) || ctype_digit((string) $input))) {
            return (string) $input;
        }

        // 3. Header
        $header = $request->header(self::BRANCH_HEADER_NAME);
        if ($header !== null && ctype_digit($header)) {
            return $header;
        }

        return null;
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