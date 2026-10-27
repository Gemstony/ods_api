<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\LogoutRequest;
use App\Http\Requests\Api\V1\Auth\RefreshTokenRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\RefreshTokenService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Requests\Api\V1\Auth\RegisterDistributorRequest;
use App\Models\Role;

/**
 * Authentication endpoints.
 *
 * Response envelope (all endpoints):
 *   success: { "success": true,  "message": "...", "data":    {...}|null }
 *   failure: { "success": false, "message": "...", "errors":  {...}|null }
 *
 * Token lifecycle:
 *   - Access token:   60 min (from JWT_TTL), stateless, sent as Bearer.
 *   - Refresh token:  14 days (from JWT_REFRESH_TTL), rotation on every use.
 */
class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
    ) {
    }

    // ─────────────────────────────────────────────────────────
    // LOGIN
    // ─────────────────────────────────────────────────────────

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::with('role.permissions')
            ->where('email', $request->email)
            ->first();

        // ─── Password check (generic failure message, no info leak) ─
        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->error('Invalid email or password.', null, 401);
        }

        // ─── Status gate ─────────────────────────────────────────
        if (!$user->isActive()) {
            $message = match ($user->status) {
                User::STATUS_PENDING => 'Your account is pending approval. Please wait for the administrator.',
                User::STATUS_SUSPENDED => 'Your account has been suspended. Contact the administrator.',
                User::STATUS_DEACTIVATED => 'Your account has been deactivated.',
                default => 'Your account is not active.',
            };
            return $this->error($message, null, 403);
        }

        // ─── Issue tokens ─────────────────────────────────────────
        $accessToken = JWTAuth::fromUser($user);
        [, $refreshToken] = $this->refreshTokens->issue($user, $request);

        // ─── Audit ────────────────────────────────────────────────
        AuditLog::record(
            actor: $user,
            action: 'auth.login',
            description: "Logged in from {$request->ip()}",
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return $this->success([
            'user' => new UserResource($user),
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'bearer',
            'expires_in' => $this->accessTokenTtlSeconds(),
        ], 'Login successful.');
    }

    // ─────────────────────────────────────────────────────────
    // REFRESH (with rotation)
    // ─────────────────────────────────────────────────────────

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $stored = $this->refreshTokens->find($request->refresh_token);

        if (!$stored || !$stored->isUsable()) {
            return $this->error('Invalid or expired refresh token.', null, 401);
        }

        $user = $stored->user()->with('role.permissions')->first();

        if (!$user || !$user->isActive()) {
            // Revoke the token even if the user is inactive — no point in
            // keeping it around for the next attempt.
            $this->refreshTokens->revoke($stored);
            return $this->error(
                'Your account is not active. Please contact the administrator.',
                null,
                403,
            );
        }

        // Rotation: revoke old, issue new — atomically enough that a replay
        // of the OLD token fails immediately.
        $this->refreshTokens->revoke($stored);
        $accessToken = JWTAuth::fromUser($user);
        [, $newRefresh] = $this->refreshTokens->issue($user, $request);

        return $this->success([
            'user' => new UserResource($user),
            'access_token' => $accessToken,
            'refresh_token' => $newRefresh,
            'token_type' => 'bearer',
            'expires_in' => $this->accessTokenTtlSeconds(),
        ], 'Token refreshed.');
    }

    // ─────────────────────────────────────────────────────────
    // LOGOUT (this device)
    // ─────────────────────────────────────────────────────────

    public function logout(LogoutRequest $request): JsonResponse
    {
        // Revoke refresh token if provided AND it belongs to the caller.
        if ($request->filled('refresh_token')) {
            $stored = $this->refreshTokens->find($request->refresh_token);
            if ($stored && $stored->user_id === $request->user()->id) {
                $this->refreshTokens->revoke($stored);
            }
        }

        // Best-effort access-token invalidation (adds to blacklist via cache).
        $this->invalidateCurrentAccessToken();

        return $this->success(null, 'Logged out successfully.');
    }

    // ─────────────────────────────────────────────────────────
    // LOGOUT ALL (all devices)
    // ─────────────────────────────────────────────────────────

    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $this->refreshTokens->revokeAllForUser($user);

        $this->invalidateCurrentAccessToken();

        AuditLog::record(
            actor: $user,
            action: 'auth.logout_all',
            description: "Logged out from all devices ({$count} session(s) revoked)",
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return $this->success(null, 'Logged out from all devices.');
    }

    // ─────────────────────────────────────────────────────────
    // ME
    // ─────────────────────────────────────────────────────────

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role.permissions', 'branch');
        return $this->success(new UserResource($user), 'OK');
    }

    // ─────────────────────────────────────────────────────────
    // REGISTER DISTRIBUTOR (self-registration, pending approval)
    // ─────────────────────────────────────────────────────────

    public function registerDistributor(
        RegisterDistributorRequest $request,
    ): JsonResponse {
        $distributorRole = Role::where('name', Role::DISTRIBUTOR)->first();
        if (!$distributorRole) {
            // Seeder should have created this; if it didn't, the deployment
            // is broken. Surface a server error rather than creating a user
            // with no role.
            return $this->error(
                'Registration is temporarily unavailable. Please try again later.',
                null,
                500,
            );
        }

        $user = User::create([
            'role_id' => $distributorRole->id,
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'distributor_id' => $request->input('distributor_id'),
            'password' => $request->input('password'), // auto-hashed via cast
            'status' => User::STATUS_PENDING,
        ]);

        // Log the registration. Actor is null because the user doesn't have
        // a token yet — this is a self-service public action.
        AuditLog::record(
            actor: null,
            action: 'user.self_registered',
            description: "Distributor self-registered: {$user->email}",
            subject: $user,
            changes: [
                'role' => Role::DISTRIBUTOR,
                'distributor_id' => $user->distributor_id,
                'status' => $user->status,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $user->load('role.permissions');

        return $this->success(
            data: new UserResource($user),
            message: 'Registration successful. Your account is pending approval. '
            . 'You will be notified once an administrator approves it.',
            status: 201,
        );
    }

    // ─────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────

    protected function accessTokenTtlSeconds(): int
    {
        return (int) config('jwt.ttl', 60) * 60;
    }

    /**
     * Best-effort access-token invalidation. Requires `blacklist_enabled`
     * and a persistent cache store. If anything goes wrong (no token in
     * request, cache unavailable), we log and continue — the token will
     * naturally expire within its TTL.
     */
    protected function invalidateCurrentAccessToken(): void
    {
        try {
            $token = JWTAuth::getToken();
            if ($token) {
                JWTAuth::invalidate($token);
            }
        } catch (Throwable $e) {
            // Silently ignore — logout semantics depend on refresh revocation,
            // not on access-token blacklist.
        }
    }
}