<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSmsLog extends Model
{
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
        'beem_code',
        'beem_message',
        'beem_request_id',
        'notification_id',
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
            'beem_code'        => 'integer',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}