<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cached ClickPesa access token (Phase 11.1).
 *
 * One row per merchant client_id (identified by SHA-256 hash of the
 * raw client_id — see migration for rationale). Overwritten in place
 * on refresh. Never carries history.
 */
class ClickPesaToken extends Model
{
    /**
     * Explicit table name — Laravel would default to `click_pesa_tokens`
     * (snake_cased class name), but the migration creates `clickpesa_tokens`
     * (matching ClickPesa's brand spelling).
     */
    protected $table = 'clickpesa_tokens';

    protected $fillable = [
        'client_id_hash',
        'access_token',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}