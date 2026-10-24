<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single payment attempt for one stage of a delivery.
 */
class Payment extends Model
{
    use HasFactory;

    public const STAGE_FULL           = 'full';
    public const STAGE_ODS_FEE        = 'ods_fee';
    public const STAGE_TRANSPORT_FARE = 'transport_fare';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_REFUNDED   = 'refunded';
    public const STATUS_EXPIRED    = 'expired';

    public const METHOD_CLICKPESA = 'clickpesa';
    public const METHOD_CASH      = 'cash';
    public const METHOD_MANUAL    = 'manual';

    protected $fillable = [
        'delivery_id',
        'stage',
        'amount',
        'currency',
        'method',
        'status',
        'reference',
        'provider_reference',
        'initiated_by',
        'initiated_at',
        'paid_at',
        'failed_at',
        'expires_at',
        'failure_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'float',
            'initiated_at' => 'datetime',
            'paid_at'      => 'datetime',
            'failed_at'    => 'datetime',
            'expires_at'   => 'datetime',
            'metadata'     => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            if (empty($payment->reference)) {
                $payment->reference = static::nextReference();
            }
        });
    }

    protected static function nextReference(): string
    {
        return sprintf(
            'PAY-%s-%s',
            now()->format('Ymd'),
            strtoupper(bin2hex(random_bytes(3))),
        );
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function callbacks(): HasMany
    {
        return $this->hasMany(ClickPesaCallback::class);
    }

    // ─── State helpers ────────────────────────────────────────
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    // ─── Scopes ───────────────────────────────────────────────
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    public function scopeForDelivery($query, int $deliveryId)
    {
        return $query->where('delivery_id', $deliveryId);
    }
}