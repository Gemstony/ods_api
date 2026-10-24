<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single refresh-token record.
 *
 * Never stores the raw token — only its SHA-256 hash. The raw token is
 * handed to the client once, on issue, and never returned again.
 */
class RefreshToken extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'revoked_at',
        'device_name',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired() && ! $this->isRevoked();
    }
}