<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Issues, verifies, and revokes refresh tokens.
 *
 * Raw tokens are returned to the caller exactly once, at issue time. Only
 * the SHA-256 hash is persisted. Rotation (revoke old + issue new) happens
 * in AuthController::refresh() using [revoke] then [issue].
 */
class RefreshTokenService
{
    /**
     * Issues a new refresh token for the user and returns [model, rawToken].
     *
     * @return array{0: RefreshToken, 1: string}
     */
    public function issue(User $user, Request $request): array
    {
        $rawToken = $this->generateRaw();
        $hash     = $this->hash($rawToken);

        $model = RefreshToken::create([
            'user_id'     => $user->id,
            'token_hash'  => $hash,
            'expires_at'  => now()->addMinutes($this->ttlMinutes()),
            'device_name' => $this->deviceName($request),
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return [$model, $rawToken];
    }

    /**
     * Finds the stored token row for a raw token string, without checking
     * usability. Callers must call [$token->isUsable()] before trusting it.
     */
    public function find(string $rawToken): ?RefreshToken
    {
        return RefreshToken::where('token_hash', $this->hash($rawToken))->first();
    }

    /**
     * Revokes a token (idempotent — safe to call on an already-revoked row).
     */
    public function revoke(RefreshToken $token): void
    {
        if ($token->revoked_at === null) {
            $token->update(['revoked_at' => now()]);
        }
    }

    /**
     * Revokes all active refresh tokens for a user.
     * Returns the number of tokens revoked.
     */
    public function revokeAllForUser(User $user): int
    {
        return RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * How long a refresh token lives, in minutes. Reads `jwt.refresh_ttl`
     * from .env (defaults to 14 days).
     */
    public function ttlMinutes(): int
    {
        return (int) config('jwt.refresh_ttl', 20160);
    }

    // ─── Internal ────────────────────────────────────────────

    protected function generateRaw(): string
    {
        return bin2hex(random_bytes(32));
    }

    protected function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    protected function deviceName(Request $request): ?string
    {
        // Client can send X-Device-Name to help users identify sessions.
        $custom = $request->header('X-Device-Name');
        if (is_string($custom) && $custom !== '') {
            return substr($custom, 0, 100);
        }

        // Fallback: parse the User-Agent's first segment (e.g. "Flutter").
        $ua = (string) $request->userAgent();
        if ($ua === '') {
            return null;
        }

        return substr($ua, 0, 100);
    }
}