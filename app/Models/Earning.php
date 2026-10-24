<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compensation ledger for riders and dispatchers.
 *
 * NOTE: `transport_fare` is intentionally absent from this table — it's
 * money owed to bus/cargo providers, not user compensation. It lives only
 * on `dispatcher_tickets`.
 */
class Earning extends Model
{
    use HasFactory;

    public const TYPE_RIDER_BASELINE      = 'rider_baseline';
    public const TYPE_RIDER_BONUS         = 'rider_bonus';
    public const TYPE_DISPATCHER_FLAT     = 'dispatcher_flat';
    public const TYPE_DISPATCHER_SHIFT    = 'dispatcher_shift';
    public const TYPE_DISPATCHER_PERCENT  = 'dispatcher_percent';
    public const TYPE_ADJUSTMENT          = 'adjustment';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_PAID      = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'delivery_id',
        'type',
        'amount',
        'currency',
        'status',
        'earned_at',
        'approved_by',
        'approved_at',
        'payout_id',
        'paid_at',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'float',
            'earned_at'   => 'datetime',
            'approved_at' => 'datetime',
            'paid_at'     => 'datetime',
            'metadata'    => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    // ─── State helpers ────────────────────────────────────────
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isAdjustment(): bool
    {
        return $this->type === self::TYPE_ADJUSTMENT;
    }

    public function isRiderEarning(): bool
    {
        return in_array($this->type, [
            self::TYPE_RIDER_BASELINE,
            self::TYPE_RIDER_BONUS,
        ], true);
    }

    public function isDispatcherEarning(): bool
    {
        return in_array($this->type, [
            self::TYPE_DISPATCHER_FLAT,
            self::TYPE_DISPATCHER_SHIFT,
            self::TYPE_DISPATCHER_PERCENT,
        ], true);
    }
}