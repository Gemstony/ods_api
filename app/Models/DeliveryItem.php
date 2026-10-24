<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single line item within a delivery — either an EXGO order reference
 * (M-Commerce) or a manually entered product (WhatsApp).
 */
class DeliveryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'exgo_order_id',
        'product_name',
        'product_code',
        'quantity',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity'   => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /** True if this row represents an EXGO order reference. */
    public function isExgoReference(): bool
    {
        return $this->exgo_order_id !== null;
    }

    /** True if this row represents a manually entered product. */
    public function isManualProduct(): bool
    {
        return $this->product_name !== null;
    }
}