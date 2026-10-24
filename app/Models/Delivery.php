<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A delivery request for one of the three ODS services.
 *
 * Never construct directly from controllers — always use a factory or the
 * Phase 7/8/9 service layer which handles status transitions, pricing
 * snapshots, and verification-code generation.
 */
class Delivery extends Model
{
    use HasFactory, SoftDeletes;

    // ─── Service types ────────────────────────────────────────
    public const SERVICE_RECEIVE  = 'receive';
    public const SERVICE_SEND     = 'send';
    public const SERVICE_REGIONAL = 'regional';

    // ─── Statuses ─────────────────────────────────────────────
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID            = 'paid';
    public const STATUS_BATCHED         = 'batched';
    public const STATUS_ASSIGNED        = 'assigned';
    public const STATUS_ACCEPTED        = 'accepted';
    public const STATUS_PICKED_UP       = 'picked_up';
    public const STATUS_DISPATCHED      = 'dispatched';
    public const STATUS_DELIVERED       = 'delivered';
    public const STATUS_CANCELLED       = 'cancelled';
    public const STATUS_FAILED          = 'failed';
    public const STATUS_EXPIRED         = 'expired';

    protected $fillable = [
        'tracking_id',
        'distributor_id',
        'pickup_branch_id',
        'service_type',
        'status',
        'pickup_address', 'pickup_latitude', 'pickup_longitude', 'pickup_contact_phone',
        'destination_address', 'destination_latitude', 'destination_longitude',
        'destination_region', 'destination_district', 'destination_bus_stop',
        'recipient_name', 'recipient_phone',
        'distance_km', 'rate_per_km', 'minimum_fee', 'rounding_increment',
        'calculated_fee', 'ods_delivery_fee', 'bus_cargo_fare',
        'discount_amount', 'discount_reason', 'total_amount', 'currency',
        'rider_id', 'assigned_at', 'assignment_expires_at', 'accepted_at',
        'picked_up_at', 'delivered_at',
        'dispatcher_id', 'dispatched_at',
        'batch_id', 'batch_window_start', 'batch_window_end', 'assignment_group_id',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'pickup_latitude'      => 'float',
            'pickup_longitude'     => 'float',
            'destination_latitude' => 'float',
            'destination_longitude'=> 'float',
            'distance_km'          => 'float',
            'rate_per_km'          => 'float',
            'minimum_fee'          => 'float',
            'calculated_fee'       => 'float',
            'ods_delivery_fee'     => 'float',
            'bus_cargo_fare'       => 'float',
            'discount_amount'      => 'float',
            'total_amount'         => 'float',
            'assigned_at'          => 'datetime',
            'assignment_expires_at'=> 'datetime',
            'accepted_at'          => 'datetime',
            'picked_up_at'         => 'datetime',
            'delivered_at'         => 'datetime',
            'dispatched_at'        => 'datetime',
            'batch_window_start'   => 'datetime',
            'batch_window_end'     => 'datetime',
            'cancelled_at'         => 'datetime',
        ];
    }

    // ─── Boot: auto-generate tracking_id ───────────────────────
    protected static function booted(): void
    {
        static::creating(function (Delivery $delivery) {
            if (empty($delivery->tracking_id)) {
                $delivery->tracking_id = static::nextTrackingId();
            }
        });
    }

    protected static function nextTrackingId(): string
    {
        // ODS-YYYYMMDD-XXXXXX where XXXXXX is 6 random hex chars.
        // Uniqueness is enforced by the DB constraint on tracking_id;
        // on the very rare collision, the insert fails and can be retried.
        return sprintf(
            'ODS-%s-%s',
            now()->format('Ymd'),
            strtoupper(bin2hex(random_bytes(3))),
        );
    }

    // ─── Relations ─────────────────────────────────────────────
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    public function pickupBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'pickup_branch_id');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatcher_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class)->orderBy('sort_order');
    }

    public function verificationCodes(): HasMany
    {
        return $this->hasMany(VerificationCode::class);
    }

    public function dispatcherTicket(): HasOne
    {
        return $this->hasOne(DispatcherTicket::class);
    }

    // ─── Service-type helpers ─────────────────────────────────
    public function isReceive(): bool
    {
        return $this->service_type === self::SERVICE_RECEIVE;
    }

    public function isSend(): bool
    {
        return $this->service_type === self::SERVICE_SEND;
    }

    public function isRegional(): bool
    {
        return $this->service_type === self::SERVICE_REGIONAL;
    }

    public function isLocal(): bool
    {
        return in_array($this->service_type, [self::SERVICE_RECEIVE, self::SERVICE_SEND], true);
    }

    // ─── Status helpers ───────────────────────────────────────
    public function isPaid(): bool
    {
        return $this->status !== self::STATUS_PENDING_PAYMENT;
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function isCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
        ], true);
    }

    public function isActive(): bool
    {
        return ! $this->isDelivered() && ! $this->isCancelled();
    }

    // ─── Scopes ───────────────────────────────────────────────
    public function scopePendingPayment($query)
    {
        return $query->where('status', self::STATUS_PENDING_PAYMENT);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
        ]);
    }

    public function scopeForRider($query, int $riderId)
    {
        return $query->where('rider_id', $riderId);
    }

    public function scopeForDistributor($query, int $distributorId)
    {
        return $query->where('distributor_id', $distributorId);
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('pickup_branch_id', $branchId);
    }

    public function scopeOfService($query, string $serviceType)
    {
        return $query->where('service_type', $serviceType);
    }
}