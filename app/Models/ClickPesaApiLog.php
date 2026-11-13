<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only log of every ClickPesa HTTP call (Phase 11.1).
 *
 * Written by ClickPesaClient::httpCall() — one row per round-trip,
 * always, including on exceptions. Never updated after insert.
 */
class ClickPesaApiLog extends Model
{
    /**
     * Explicit table name — Laravel's default pluralizer would look
     * for `click_pesa_api_logs` (snake_cased class name), but the
     * migration creates `clickpesa_api_logs` (matching ClickPesa's
     * brand spelling). Same reason ClickPesaCallback declares $table.
     */
    protected $table = 'clickpesa_api_logs';

    public $timestamps = false;

    protected $fillable = [
        'correlation_id',
        'method',
        'endpoint',
        'full_url',
        'request_headers',
        'request_body',
        'response_status',
        'response_body',
        'response_headers',
        'error_message',
        'duration_ms',
        'payment_id',
        'triggered_by',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'request_headers'  => 'array',
            'request_body'     => 'array',
            'response_body'    => 'array',
            'response_headers' => 'array',
            'response_status'  => 'integer',
            'duration_ms'      => 'integer',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}