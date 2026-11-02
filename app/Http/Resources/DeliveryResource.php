<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a `Delivery` model into the JSON the Flutter client expects.
 *
 * Field names match `Delivery.fromJson` in `lib/data/models/delivery.dart`
 * exactly — no client-side adaptation needed. `items` is included only
 * when the relation is eager-loaded (`whenLoaded`), matching Flutter's
 * nullable `items` field.
 */
class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'tracking_id'            => $this->tracking_id,
            'distributor_id'         => $this->distributor_id,
            'pickup_branch_id'       => $this->pickup_branch_id,
            'service_type'           => $this->service_type,
            'status'                 => $this->status,

            'pickup_address'         => $this->pickup_address,
            'pickup_latitude'        => $this->pickup_latitude === null
                ? null
                : (float) $this->pickup_latitude,
            'pickup_longitude'       => $this->pickup_longitude === null
                ? null
                : (float) $this->pickup_longitude,
            'pickup_contact_phone'   => $this->pickup_contact_phone,

            'destination_address'    => $this->destination_address,
            'destination_latitude'   => $this->destination_latitude === null
                ? null
                : (float) $this->destination_latitude,
            'destination_longitude'  => $this->destination_longitude === null
                ? null
                : (float) $this->destination_longitude,
            'destination_region'     => $this->destination_region,
            'destination_district'   => $this->destination_district,
            'destination_bus_stop'   => $this->destination_bus_stop,

            'recipient_name'         => $this->recipient_name,
            'recipient_phone'        => $this->recipient_phone,

            'distance_km'            => $this->distance_km === null
                ? null
                : (float) $this->distance_km,
            'rate_per_km'            => $this->rate_per_km === null
                ? null
                : (float) $this->rate_per_km,
            'minimum_fee'            => $this->minimum_fee === null
                ? null
                : (float) $this->minimum_fee,
            'rounding_increment'     => $this->rounding_increment,
            'calculated_fee'         => $this->calculated_fee === null
                ? null
                : (float) $this->calculated_fee,
            'ods_delivery_fee'       => $this->ods_delivery_fee === null
                ? null
                : (float) $this->ods_delivery_fee,
            'bus_cargo_fare'         => $this->bus_cargo_fare === null
                ? null
                : (float) $this->bus_cargo_fare,
            'discount_amount'        => (float) ($this->discount_amount ?? 0),
            'discount_reason'        => $this->discount_reason,
            'total_amount'           => $this->total_amount === null
                ? null
                : (float) $this->total_amount,
            'currency'               => $this->currency,

            'rider_id'               => $this->rider_id,
            'assigned_at'            => $this->assigned_at?->toIso8601String(),
            'assignment_expires_at'  => $this->assignment_expires_at?->toIso8601String(),
            'accepted_at'            => $this->accepted_at?->toIso8601String(),
            'picked_up_at'           => $this->picked_up_at?->toIso8601String(),
            'delivered_at'           => $this->delivered_at?->toIso8601String(),

            'dispatcher_id'          => $this->dispatcher_id,
            'dispatched_at'          => $this->dispatched_at?->toIso8601String(),

            'batch_id'               => $this->batch_id,
            'batch_window_start'     => $this->batch_window_start?->toIso8601String(),
            'batch_window_end'       => $this->batch_window_end?->toIso8601String(),
            'assignment_group_id'    => $this->assignment_group_id,

            'cancelled_at'           => $this->cancelled_at?->toIso8601String(),
            'cancelled_by'           => $this->cancelled_by,
            'cancellation_reason'    => $this->cancellation_reason,

            'notes'                  => $this->notes,

            'items'                  => $this->whenLoaded('items', function () {
                return DeliveryItemResource::collection($this->items);
            }),

            'created_at'             => $this->created_at?->toIso8601String(),
            'updated_at'             => $this->updated_at?->toIso8601String(),
            'deleted_at'             => $this->deleted_at?->toIso8601String(),
        ];
    }
}