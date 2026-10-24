<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dispute record — see migration for lifecycle details.
 */
class Dispute extends Model
{
    use HasFactory;

    public const CATEGORY_DELIVERY     = 'delivery';
    public const CATEGORY_PAYMENT      = 'payment';
    public const CATEGORY_PACKAGE      = 'package';
    public const CATEGORY_COMPENSATION = 'compensation';
    public const CATEGORY_OTHER        = 'other';

    public const STATUS_OPEN       = 'open';
    public const STATUS_IN_REVIEW  = 'in_review';
    public const STATUS_RESOLVED   = 'resolved';
    public const STATUS_CLOSED     = 'closed';
    public const STATUS_ESCALATED  = 'escalated';

    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'delivery_id',
        'opened_by',
        'against_user_id',
        'category',
        'subject',
        'description',
        'priority',
        'status',
        'resolution',
        'resolved_by',
        'resolved_at',
        'attachments',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function againstUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'against_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ─── State helpers ────────────────────────────────────────
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_OPEN,
            self::STATUS_IN_REVIEW,
            self::STATUS_ESCALATED,
        ], true);
    }

    // ─── Scopes ───────────────────────────────────────────────
    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            self::STATUS_OPEN,
            self::STATUS_IN_REVIEW,
            self::STATUS_ESCALATED,
        ]);
    }

    public function scopeForDelivery($query, int $deliveryId)
    {
        return $query->where('delivery_id', $deliveryId);
    }
}