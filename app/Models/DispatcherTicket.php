<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A regional dispatch ticket recording the handover to a bus/cargo
 * provider. One per regional delivery.
 *
 * IMPORTANT: `transport_fare` is money owed to the provider — it is
 * NEVER dispatcher income. Dispatcher compensation lives in `earnings`.
 */
class DispatcherTicket extends Model
{
    use HasFactory;

    public const PROVIDER_BUS   = 'bus';
    public const PROVIDER_CARGO = 'cargo';
    public const PROVIDER_OTHER = 'other';

    protected $fillable = [
        'delivery_id',
        'dispatcher_id',
        'provider_name',
        'provider_type',
        'ticket_number',
        'route_from',
        'route_to',
        'transport_fare',
        'currency',
        'issued_at',
        'provider_confirmed_at',
        'provider_confirmed_by',
        'ticket_image_path',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'transport_fare'        => 'float',
            'issued_at'             => 'datetime',
            'provider_confirmed_at' => 'datetime',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatcher_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_confirmed_by');
    }

    public function isConfirmed(): bool
    {
        return $this->provider_confirmed_at !== null;
    }
}