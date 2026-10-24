<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app notification with fan-out to SMS / push.
 *
 * NOTE: This is NOT Laravel's `Illuminate\Notifications\Notification`.
 * User no longer uses the `Notifiable` trait — we manage notifications
 * explicitly via [send()] and Phase 13's channel dispatcher.
 */
class Notification extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';

    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'channels',
        'status',
        'priority',
        'sent_at',
        'read_at',
        'dismissed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'data'         => 'array',
            'channels'     => 'array',
            'sent_at'      => 'datetime',
            'read_at'      => 'datetime',
            'dismissed_at' => 'datetime',
            'expires_at'   => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── State helpers ────────────────────────────────────────
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }

    public function dismiss(): void
    {
        if ($this->dismissed_at === null) {
            $this->update(['dismissed_at' => now()]);
        }
    }

    // ─── Scopes ───────────────────────────────────────────────
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at')->whereNull('dismissed_at');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    // ─── Factory helper ───────────────────────────────────────
    /**
     * Creates a notification record. Phase 13 will additionally dispatch
     * the SMS/push jobs based on `channels`.
     */
    public static function send(
        User $user,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        array $channels = ['in_app'],
        string $priority = self::PRIORITY_NORMAL,
        ?int $expiresInHours = null,
    ): self {
        return static::create([
            'user_id'    => $user->id,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'data'       => $data,
            'channels'   => $channels,
            'status'     => self::STATUS_PENDING,
            'priority'   => $priority,
            'expires_at' => $expiresInHours !== null ? now()->addHours($expiresInHours) : null,
        ]);
    }
}