<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'delivery_id'     => $this->delivery_id,
            'opened_by'       => $this->opened_by,
            'against_user_id' => $this->against_user_id,
            'category'        => $this->category,
            'subject'         => $this->subject,
            'description'     => $this->description,
            'priority'        => $this->priority,
            'status'          => $this->status,
            'resolution'      => $this->resolution,
            'resolved_by'     => $this->resolved_by,
            'resolved_at'     => $this->resolved_at?->toIso8601String(),
            'attachments'     => $this->attachments ?? [],
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),

            'opened_by_user' => $this->whenLoaded('openedBy', fn () => $this->openedBy === null ? null : [
                'id'   => $this->openedBy->id,
                'name' => $this->openedBy->name,
            ]),

            'against_user' => $this->whenLoaded('againstUser', fn () => $this->againstUser === null ? null : [
                'id'   => $this->againstUser->id,
                'name' => $this->againstUser->name,
            ]),

            'resolved_by_user' => $this->whenLoaded('resolvedBy', fn () => $this->resolvedBy === null ? null : [
                'id'   => $this->resolvedBy->id,
                'name' => $this->resolvedBy->name,
            ]),

            'delivery' => $this->whenLoaded('delivery', fn () => $this->delivery === null ? null : [
                'id'           => $this->delivery->id,
                'tracking_id'  => $this->delivery->tracking_id,
                'status'       => $this->delivery->status,
                'service_type' => $this->delivery->service_type,
            ]),
        ];
    }
}