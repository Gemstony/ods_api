<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operating expense record.
 */
class Expense extends Model
{
    use HasFactory;

    public const CATEGORY_FUEL              = 'fuel';
    public const CATEGORY_SALARY            = 'salary';
    public const CATEGORY_RENT              = 'rent';
    public const CATEGORY_UTILITIES         = 'utilities';
    public const CATEGORY_SUPPLIES          = 'supplies';
    public const CATEGORY_MAINTENANCE       = 'maintenance';
    public const CATEGORY_TRANSPORT_PROVIDER = 'transport_provider';
    public const CATEGORY_OTHER             = 'other';

    protected $fillable = [
        'category',
        'amount',
        'currency',
        'description',
        'notes',
        'recorded_by',
        'occurred_at',
        'payment_reference',
        'attachment_path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'float',
            'occurred_at' => 'datetime',
            'metadata'    => 'array',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}