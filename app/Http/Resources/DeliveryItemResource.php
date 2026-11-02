<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a `DeliveryItem` model into the JSON the Flutter client expects.
 *
 * Field names match `DeliveryItem.fromJson` in
 * `lib/data/models/delivery_item.dart` exactly.
 */
class DeliveryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'delivery_id'   => $this->delivery_id,
            'exgo_order_id' => $this->exgo_order_id,
            'product_name'  => $this->product_name,
            'product_code'  => $this->product_code,
            'quantity'      => $this->quantity,
            'notes'         => $this->notes,
            'sort_order'    => $this->sort_order,
            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),
        ];
    }
}