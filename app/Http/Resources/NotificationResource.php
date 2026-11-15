<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the Flutter `AppNotification.fromJson` contract exactly.
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'user_id'      => $this->user_id,
            'type'         => $this->type,
            'title'        => $this->title,
            'body'         => $this->body,
            'data'         => $this->data,
            'channels'     => $this->channels ?? ['in_app'],
            'status'       => $this->status,
            'priority'     => $this->priority,
            'sent_at'      => $this->sent_at?->toIso8601String(),
            'read_at'      => $this->read_at?->toIso8601String(),
            'dismissed_at' => $this->dismissed_at?->toIso8601String(),
            'expires_at'   => $this->expires_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
            'updated_at'   => $this->updated_at?->toIso8601String(),
        ];
    }
}