<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw webhook log. Mostly audit — Phase 11's controller is the only code
 * that should write or process these.
 */
class ClickPesaCallback extends Model
{
    use HasFactory;

    protected $table = 'clickpesa_callbacks';

    protected $fillable = [
        'provider_event_id',
        'event_type',
        'payment_id',
        'signature_valid',
        'payload',
        'ip_address',
        'user_agent',
        'received_at',
        'processed_at',
        'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload'         => 'array',
            'received_at'     => 'datetime',
            'processed_at'    => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}