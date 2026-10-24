<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only log of payment-provider interactions.
 */
class PaymentTransaction extends Model
{
    use HasFactory;

    public const TYPE_INITIATE = 'initiate';
    public const TYPE_CALLBACK = 'callback';
    public const TYPE_VERIFY   = 'verify';
    public const TYPE_REFUND   = 'refund';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_ERROR   = 'error';

    protected $fillable = [
        'payment_id',
        'type',
        'provider',
        'provider_reference',
        'status',
        'request_payload',
        'response_payload',
        'error_message',
        'attempted_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'request_payload'  => 'array',
            'response_payload' => 'array',
            'attempted_at'     => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}