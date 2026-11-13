<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-facing payout representation (Phase 11.4).
 */
class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'reference' => $this->reference,
            'status' => $this->status,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),

            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
            ]),

            'processed_by' => $this->whenLoaded('processedBy', fn () => $this->processedBy === null ? null : [
                'id' => $this->processedBy->id,
                'name' => $this->processedBy->name,
            ]),

            'earnings' => $this->whenLoaded('earnings', fn () =>
                $this->earnings->map(fn ($e) => [
                    'id' => $e->id,
                    'delivery_id' => $e->delivery_id,
                    'type' => $e->type,
                    'amount' => (float) $e->amount,
                    'status' => $e->status,
                ])->all()
            ),
        ];
    }
}