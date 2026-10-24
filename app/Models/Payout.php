<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payout batch — earnings grouped and paid to a user.
 */
class Payout extends Model
{
    use HasFactory;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID       = 'paid';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';

    public const METHOD_MOBILE = 'mobile';
    public const METHOD_BANK   = 'bank';
    public const METHOD_CASH   = 'cash';

    protected $fillable = [
        'user_id',
        'amount',
        'currency',
        'method',
        'reference',
        'status',
        'requested_at',
        'processed_at',
        'processed_by',
        'failure_reason',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'float',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'metadata'     => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payout $payout) {
            if (empty($payout->reference)) {
                $payout->reference = sprintf(
                    'PO-%s-%s',
                    now()->format('Ymd'),
                    strtoupper(bin2hex(random_bytes(3))),
                );
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(Earning::class);
    }

    // ─── State helpers ────────────────────────────────────────
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}