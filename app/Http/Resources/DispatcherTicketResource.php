<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a DispatcherTicket model into the JSON the client expects.
 *
 * Field names match the `DispatcherTicket` model in `lib/data/models/`
 * (Phase 9.3). `dispatcher` is included only when the relation is
 * eager-loaded.
 */
class DispatcherTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delivery_id' => $this->delivery_id,
            'dispatcher_id' => $this->dispatcher_id,
            'provider_name' => $this->provider_name,
            'provider_type' => $this->provider_type,
            'ticket_number' => $this->ticket_number,
            'route_from' => $this->route_from,
            'route_to' => $this->route_to,
            'transport_fare' => $this->transport_fare === null
                ? null
                : (float) $this->transport_fare,
            'currency' => $this->currency,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'provider_confirmed_at' => $this->provider_confirmed_at?->toIso8601String(),
            'provider_confirmed_by' => $this->provider_confirmed_by,
            'ticket_image_path' => $this->ticket_image_path,
            'notes' => $this->notes,
            'is_confirmed' => $this->provider_confirmed_at !== null,

            'dispatcher' => $this->whenLoaded('dispatcher', function () {
                return [
                    'id' => $this->dispatcher->id,
                    'name' => $this->dispatcher->name,
                ];
            }),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}