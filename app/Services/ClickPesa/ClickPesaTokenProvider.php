<?php

namespace App\Services\ClickPesa;

use App\Models\ClickPesaToken;
use Illuminate\Support\Facades\DB;

/**
 * Storage-only manager for cached ClickPesa access tokens (Phase 11.1).
 *
 * Knows nothing about HTTP. The client calls:
 *   - findValid()  → get an unexpired cached token or null
 *   - store()      → write a fresh token (overwrites prior row)
 *   - clear()      → remove the cached token (after a 401 that proves
 *                    the token was revoked server-side)
 *
 * TTL: written at store time. Caller (ClickPesaClient) supplies the TTL
 * in minutes — the safety margin is baked in by storing `now + TTL`
 * where TTL is already reduced (default 55 min against ClickPesa's
 * 60-minute ceiling).
 */
class ClickPesaTokenProvider
{
    public function __construct(
        private readonly string $clientId,
    ) {}

    public function findValid(): ?ClickPesaToken
    {
        $hash = $this->clientIdHash();

        $token = ClickPesaToken::where('client_id_hash', $hash)->first();
        if ($token === null || $token->isExpired()) {
            return null;
        }
        return $token;
    }

    public function store(string $accessToken, int $ttlMinutes): ClickPesaToken
    {
        return DB::transaction(function () use ($accessToken, $ttlMinutes) {
            return ClickPesaToken::updateOrCreate(
                ['client_id_hash' => $this->clientIdHash()],
                [
                    'access_token' => $accessToken,
                    'expires_at'   => now()->addMinutes($ttlMinutes),
                ],
            );
        });
    }

    public function clear(): void
    {
        ClickPesaToken::where('client_id_hash', $this->clientIdHash())->delete();
    }

    private function clientIdHash(): string
    {
        return hash('sha256', $this->clientId);
    }
}