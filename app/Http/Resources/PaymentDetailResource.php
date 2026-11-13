<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rich payment representation for Owner ledger drill-down (Phase 11.4).
 *
 * Includes nested transactions, callbacks, and ClickPesa API logs when
 * eager-loaded. Safe: every payload from ClickPesaApiLog was already
 * redacted at write time (see Phase 11.1 ClickPesaClient).
 */
class PaymentDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'provider_reference' => $this->provider_reference,
            'delivery_id' => $this->delivery_id,
            'stage' => $this->stage,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'metadata' => $this->metadata,
            'initiated_at' => $this->initiated_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'initiated_by' => $this->whenLoaded('initiatedBy', fn () => [
                'id' => $this->initiatedBy?->id,
                'name' => $this->initiatedBy?->name,
                'email' => $this->initiatedBy?->email,
            ]),

            'delivery' => $this->whenLoaded('delivery', fn () => [
                'id' => $this->delivery?->id,
                'tracking_id' => $this->delivery?->tracking_id,
                'status' => $this->delivery?->status,
                'service_type' => $this->delivery?->service_type,
                'total_amount' => $this->delivery?->total_amount !== null
                    ? (float) $this->delivery->total_amount
                    : null,
                'distributor_id' => $this->delivery?->distributor_id,
            ]),

            'transactions' => $this->whenLoaded('transactions', fn () =>
                $this->transactions->map(fn ($tx) => [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'provider' => $tx->provider,
                    'provider_reference' => $tx->provider_reference,
                    'status' => $tx->status,
                    'error_message' => $tx->error_message,
                    'attempted_at' => $tx->attempted_at?->toIso8601String(),
                    'request_payload' => $tx->request_payload,
                    'response_payload' => $tx->response_payload,
                ])->all()
            ),

            'callbacks' => $this->whenLoaded('callbacks', fn () =>
                $this->callbacks->map(fn ($cb) => [
                    'id' => $cb->id,
                    'provider_event_id' => $cb->provider_event_id,
                    'event_type' => $cb->event_type,
                    'signature_valid' => (bool) $cb->signature_valid,
                    'received_at' => $cb->received_at?->toIso8601String(),
                    'processed_at' => $cb->processed_at?->toIso8601String(),
                    'processing_error' => $cb->processing_error,
                    'payload' => $cb->payload,
                ])->all()
            ),

            'clickpesa_api_logs' => $this->whenLoaded('clickpesaApiLogs', fn () =>
                $this->clickpesaApiLogs->map(fn ($log) => [
                    'id' => $log->id,
                    'correlation_id' => $log->correlation_id,
                    'method' => $log->method,
                    'endpoint' => $log->endpoint,
                    'response_status' => $log->response_status,
                    'duration_ms' => $log->duration_ms,
                    'error_message' => $log->error_message,
                    'triggered_by' => $log->triggered_by,
                    'request_headers' => $log->request_headers,
                    'request_body' => $log->request_body,
                    'response_body' => $log->response_body,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])->all()
            ),
        ];
    }
}