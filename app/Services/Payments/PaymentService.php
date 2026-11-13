<?php

namespace App\Services\Payments;

use App\Models\ClickPesaCallback;
use App\Models\Delivery;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ClickPesa\ClickPesaClient;
use App\Services\ClickPesa\ClickPesaException;
use App\Services\ClickPesa\PaymentStatus;
use App\Services\ClickPesa\UssdPushRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrator for delivery payments (Phase 11.2).
 *
 * Three public operations:
 *   - initiatePayment()    create Payment row + call ClickPesa USSD push
 *   - syncPayment()        actively poll ClickPesa and reconcile state
 *   - processWebhook()     handle a ClickPesa callback (post-signature-verification)
 *
 * All state transitions run inside DB transactions with row locks so
 * concurrent webhooks cannot corrupt state. Terminal states never regress.
 *
 * Idempotency: one non-terminal payment per (delivery, stage). A second
 * initiate for the same tuple throws a domain exception the controller
 * surfaces as 409; the Flutter client should call refresh instead.
 */
class PaymentService
{
    public function __construct(
        private readonly ClickPesaClient $client,
        private readonly AuditLogger $auditLogger,
    ) {}

    // ═════════════════════════════════════════════════════════
    // Initiate
    // ═════════════════════════════════════════════════════════

    /**
     * Create a Payment and push the USSD prompt via ClickPesa.
     *
     * @throws PaymentException when validation, duplicate, or ClickPesa fails
     */
    public function initiatePayment(
        Delivery $delivery,
        User $initiator,
        string $phoneNumber,
        string $stage = Payment::STAGE_FULL,
    ): Payment {
        if (! in_array($stage, [Payment::STAGE_FULL, Payment::STAGE_ODS_FEE, Payment::STAGE_TRANSPORT_FARE], true)) {
            throw PaymentException::invalidStage($stage);
        }

        if ($delivery->status !== Delivery::STATUS_PENDING_PAYMENT) {
            throw PaymentException::deliveryNotPayable($delivery->status);
        }

        $amount = $this->amountForStage($delivery, $stage);
        if ($amount <= 0) {
            throw PaymentException::invalidAmount($stage);
        }

        // Idempotency check + create inside one transaction with a
        // lock on any existing non-terminal payment for this tuple.
        $payment = DB::transaction(function () use ($delivery, $initiator, $amount, $stage) {
            $existing = Payment::where('delivery_id', $delivery->id)
                ->where('stage', $stage)
                ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw PaymentException::duplicatePending($existing);
            }

            $completed = Payment::where('delivery_id', $delivery->id)
                ->where('stage', $stage)
                ->where('status', Payment::STATUS_COMPLETED)
                ->exists();
            if ($completed) {
                throw PaymentException::alreadyPaid($stage);
            }

            return Payment::create([
                'delivery_id'  => $delivery->id,
                'stage'        => $stage,
                'amount'       => $amount,
                'currency'     => $delivery->currency ?? 'TZS',
                'method'       => Payment::METHOD_CLICKPESA,
                'status'       => Payment::STATUS_PENDING,
                'initiated_by' => $initiator->id,
                'initiated_at' => now(),
                'expires_at'   => now()->addMinutes(10),
            ]);
        });

        // Call ClickPesa OUTSIDE the DB transaction — network I/O must
        // not block a row lock. The pending Payment row is the durable
        // record even if the call times out.
        $correlationId = (string) Str::uuid();
        try {
            $response = $this->client->initiateUssdPush(
                UssdPushRequest::create(
                    orderReference: $payment->reference,
                    amount: $payment->amount,
                    phoneNumber: $phoneNumber,
                    currency: $payment->currency,
                ),
                paymentId: $payment->id,
                correlationId: $correlationId,
            );

            $providerRef = $response['id']
                ?? $response['paymentId']
                ?? $response['reference']
                ?? null;

            $payment->update([
                'status'             => Payment::STATUS_PROCESSING,
                'provider_reference' => is_string($providerRef) ? $providerRef : null,
                'metadata'           => [
                    'clickpesa_response' => $response,
                    'correlation_id'     => $correlationId,
                ],
            ]);

            $this->recordTransaction(
                payment: $payment,
                type: 'initiate',
                status: 'success',
                request: ['order_reference' => $payment->reference, 'amount' => $payment->amount],
                response: $response,
                error: null,
            );

            $this->auditLogger->logCurrentUser(
                action: 'payment.initiated',
                description: "Initiated {$stage} payment for delivery {$delivery->tracking_id}.",
                subject: $payment,
                changes: [
                    'amount'       => $payment->amount,
                    'stage'        => $stage,
                    'provider_ref' => $providerRef,
                    'phone'        => $this->maskPhone($phoneNumber),
                ],
            );

            return $payment->fresh();
        } catch (ClickPesaException $e) {
            $payment->update([
                'status'         => Payment::STATUS_FAILED,
                'failed_at'      => now(),
                'failure_reason' => substr($e->getMessage(), 0, 250),
            ]);

            $this->recordTransaction(
                payment: $payment,
                type: 'initiate',
                status: 'error',
                request: ['order_reference' => $payment->reference, 'amount' => $payment->amount],
                response: $e->responseBody,
                error: $e->getMessage(),
            );

            $this->auditLogger->logCurrentUser(
                action: 'payment.initiate_failed',
                description: "Payment initiation failed for delivery {$delivery->tracking_id}.",
                subject: $payment,
                changes: [
                    'error'    => $e->getMessage(),
                    'http_status' => $e->httpStatus,
                    'log_id'   => $e->logId,
                ],
            );

            throw PaymentException::providerError($e);
        }
    }

    // ═════════════════════════════════════════════════════════
    // Sync (poll)
    // ═════════════════════════════════════════════════════════

    /**
     * Query ClickPesa for the current status and reconcile.
     *
     * Safe to call repeatedly. Returns the (possibly updated) Payment.
     * If the provider says a terminal status we didn't know about,
     * applies it and — if it makes the delivery fully paid — transitions
     * the delivery to `paid`.
     */
    public function syncPayment(Payment $payment): Payment
    {
        if (in_array($payment->status, [
            Payment::STATUS_COMPLETED,
            Payment::STATUS_CANCELLED,
            Payment::STATUS_REFUNDED,
        ], true)) {
            return $payment; // terminal, nothing to sync
        }

        $correlationId = (string) Str::uuid();

        try {
            $result = $this->client->queryPayment(
                $payment->reference,
                paymentId: $payment->id,
                correlationId: $correlationId,
            );
        } catch (ClickPesaException $e) {
            // Log but do NOT change state — a network blip on a poll
            // must not fail an otherwise-live USSD push.
            Log::warning('ClickPesa sync failed', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
                'http'       => $e->httpStatus,
                'log_id'     => $e->logId,
            ]);
            return $payment;
        }

        $this->applyStatus($payment, $result['status'], $result['raw']);

        $this->recordTransaction(
            payment: $payment,
            type: 'verify',
            status: $result['status']->isSuccess() ? 'success' : 'pending',
            request: null,
            response: $result['raw'],
            error: null,
        );

        return $payment->fresh();
    }

    // ═════════════════════════════════════════════════════════
    // Webhook
    // ═════════════════════════════════════════════════════════

    /**
     * Process an already-verified webhook callback.
     *
     * The controller is responsible for signature verification and
     * persisting the raw payload to clickpesa_callbacks BEFORE calling
     * this method — so even if this method throws, we retain the event.
     */
    public function processWebhook(ClickPesaCallback $callback): void
    {
        $payload = $callback->payload ?? [];
        $data = $payload['data'] ?? $payload;

        $orderRef = $data['orderReference']
            ?? $data['order_reference']
            ?? $data['reference']
            ?? null;
        $statusRaw = $data['status'] ?? null;

        if (! is_string($orderRef) || $orderRef === '') {
            $this->markCallbackFailed($callback, 'Missing orderReference in payload.');
            return;
        }

        $payment = Payment::where('reference', $orderRef)->first();
        if ($payment === null) {
            $this->markCallbackFailed($callback, "No payment matches reference '{$orderRef}'.");
            return;
        }

        $callback->update(['payment_id' => $payment->id]);

        try {
            $this->applyStatus($payment, PaymentStatus::fromString($statusRaw), $payload);

            $this->recordTransaction(
                payment: $payment,
                type: 'callback',
                status: 'success',
                request: null,
                response: $payload,
                error: null,
            );

            $callback->update([
                'processed_at'     => now(),
                'processing_error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->markCallbackFailed($callback, $e->getMessage());
            throw $e;
        }
    }

    // ═════════════════════════════════════════════════════════
    // State machine core
    // ═════════════════════════════════════════════════════════

    private function applyStatus(Payment $payment, PaymentStatus $clickPesa, array $providerPayload): void
    {
        $newStatus = PaymentStatusMapper::toPaymentStatus($clickPesa);
        if ($newStatus === null) {
            Log::info('ClickPesa returned UNKNOWN status; state untouched', [
                'payment_id' => $payment->id,
                'raw'        => $providerPayload,
            ]);
            return;
        }

        DB::transaction(function () use ($payment, $newStatus, $providerPayload) {
            /** @var Payment $locked */
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if (! PaymentStatusMapper::canTransition($locked->status, $newStatus)) {
                return;
            }

            $changes = ['status' => $newStatus];
            if ($newStatus === Payment::STATUS_COMPLETED) {
                $changes['paid_at'] = now();
                $changes['failure_reason'] = null;
            } elseif ($newStatus === Payment::STATUS_FAILED) {
                $changes['failed_at'] = now();
                $changes['failure_reason'] = $this->extractFailureReason($providerPayload);
            }

            $locked->update($changes);

            if ($newStatus === Payment::STATUS_COMPLETED) {
                $this->auditLogger->log(
                    auth()->user(),
                    action: 'payment.completed',
                    description: "Payment {$locked->reference} completed ({$locked->currency} {$locked->amount}).",
                    subject: $locked,
                    changes: ['provider_payload' => $this->summarizePayload($providerPayload)],
                );
                $this->markDeliveryPaidIfComplete($locked->delivery_id);
            } elseif ($newStatus === Payment::STATUS_FAILED) {
                $this->auditLogger->log(
                    auth()->user(),
                    action: 'payment.failed',
                    description: "Payment {$locked->reference} failed: " . ($locked->failure_reason ?? 'unknown'),
                    subject: $locked,
                    changes: ['provider_payload' => $this->summarizePayload($providerPayload)],
                );
            }
        });
    }

    private function markDeliveryPaidIfComplete(int $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId) {
            /** @var Delivery $delivery */
            $delivery = Delivery::whereKey($deliveryId)->lockForUpdate()->first();
            if ($delivery === null) return;
            if ($delivery->status !== Delivery::STATUS_PENDING_PAYMENT) return;

            $outstanding = Payment::where('delivery_id', $deliveryId)
                ->where('status', '!=', Payment::STATUS_COMPLETED)
                ->count();

            if ($outstanding === 0) {
                $delivery->update(['status' => Delivery::STATUS_PAID]);
                $this->auditLogger->log(
                    auth()->user(),
                    action: 'delivery.paid',
                    description: "Delivery {$delivery->tracking_id} fully paid.",
                    subject: $delivery,
                    changes: ['status' => Delivery::STATUS_PAID],
                );
            }
        });
    }

    // ═════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════

    private function amountForStage(Delivery $delivery, string $stage): float
    {
        return match ($stage) {
            Payment::STAGE_FULL => (float) ($delivery->total_amount ?? 0),
            Payment::STAGE_ODS_FEE => max(
                0.0,
                (float) ($delivery->ods_delivery_fee ?? 0) - (float) ($delivery->discount_amount ?? 0),
            ),
            Payment::STAGE_TRANSPORT_FARE => (float) ($delivery->bus_cargo_fare ?? 0),
        };
    }

    private function recordTransaction(
        Payment $payment,
        string $type,
        string $status,
        ?array $request,
        ?array $response,
        ?string $error,
    ): void {
        PaymentTransaction::create([
            'payment_id'         => $payment->id,
            'type'               => $type,
            'provider'           => 'clickpesa',
            'provider_reference' => $payment->provider_reference,
            'status'             => $status,
            'request_payload'    => $request,
            'response_payload'   => $response,
            'error_message'      => $error,
            'attempted_at'       => now(),
            'ip_address'         => request()?->ip(),
            'user_agent'         => request()?->userAgent(),
        ]);
    }

    private function markCallbackFailed(ClickPesaCallback $callback, string $reason): void
    {
        $callback->update([
            'processing_error' => substr($reason, 0, 250),
            'processed_at'     => now(),
        ]);
        Log::warning('ClickPesa webhook could not be processed', [
            'callback_id' => $callback->id,
            'reason'      => $reason,
        ]);
    }

    private function extractFailureReason(array $payload): ?string
    {
        $data = $payload['data'] ?? $payload;
        $reason = $data['failureReason']
            ?? $data['failure_reason']
            ?? $data['message']
            ?? null;
        return is_string($reason) ? substr($reason, 0, 250) : null;
    }

    private function summarizePayload(array $payload): array
    {
        $data = $payload['data'] ?? $payload;
        return [
            'status'   => $data['status'] ?? null,
            'id'       => $data['id'] ?? null,
            'order'    => $data['orderReference'] ?? $data['order_reference'] ?? null,
            'amount'   => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
        ];
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) return '***';
        return substr($phone, 0, 3) . '***' . substr($phone, -3);
    }
}