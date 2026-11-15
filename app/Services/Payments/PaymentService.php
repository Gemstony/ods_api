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
 * Public surface used by:
 *   - Distributor\PaymentController::initiate / refresh
 *   - Webhooks\ClickPesaWebhookController::receive
 *   - Ledger\ReconciliationService::reconcile (Phase 11.4 — via applyStatus + recordTransaction)
 */
class PaymentService
{
    public function __construct(
        private readonly ClickPesaClient $client,
        private readonly AuditLogger $auditLogger,
        private readonly \App\Services\Notifications\NotificationService $notifications,
    ) {}

    // ═════════════════════════════════════════════════════════
    // Initiate
    // ═════════════════════════════════════════════════════════

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
    // Manual payments (Phase 13.7)
    // ═════════════════════════════════════════════════════════

    /**
     * Create a manual payment record (cash / manual method).
     *
     * Does NOT call ClickPesa. The row sits at `pending` until a Manager
     * approves or rejects. Delivery stays `pending_payment` until approval
     * cascades through `markDeliveryPaidIfComplete()`.
     */
    public function initiateManualPayment(
        Delivery $delivery,
        User $initiator,
        string $method,
        string $stage = Payment::STAGE_FULL,
        ?string $notes = null,
    ): Payment {
        if (! in_array($method, [Payment::METHOD_CASH, Payment::METHOD_MANUAL], true)) {
            throw PaymentException::invalidStage($method);
        }

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

        $payment = DB::transaction(function () use ($delivery, $initiator, $amount, $stage, $method, $notes) {
            // Block if any pending payment already exists for this tuple —
            // regardless of method. Prevents a Distributor from opening a
            // manual payment while a ClickPesa USSD is still in flight.
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
                'method'       => $method,
                'status'       => Payment::STATUS_PENDING,
                'initiated_by' => $initiator->id,
                'initiated_at' => now(),
                'expires_at'   => null, // no auto-expiry — Manager decides
                'metadata'     => [
                    'manual_notes' => $notes,
                    'initiated_by_name' => $initiator->name,
                ],
            ]);
        });

        $this->recordTransaction(
            payment: $payment,
            type: 'initiate',
            status: 'success',
            request: [
                'method' => $method,
                'stage' => $stage,
                'amount' => $amount,
                'notes' => $notes,
            ],
            response: ['pending_manager_approval' => true],
            error: null,
        );

        $this->auditLogger->logCurrentUser(
            action: 'payment.manual_initiated',
            description: "Initiated {$method} payment for delivery {$delivery->tracking_id}.",
            subject: $payment,
            changes: [
                'amount' => $payment->amount,
                'stage'  => $stage,
                'method' => $method,
                'notes'  => $notes,
            ],
        );

        try {
            $this->notifications->manualPaymentInitiated($payment->fresh());
        } catch (\Throwable $e) {
            Log::error('Notification write after manual payment initiate failed', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return $payment->fresh();
    }

    /**
     * Approve a manual payment. Transitions to `completed` and cascades
     * through `markDeliveryPaidIfComplete()` — if this was the last
     * outstanding stage, the delivery becomes `paid`.
     */
    public function approveManualPayment(User $approver, Payment $payment): Payment
    {
        if (! in_array($payment->method, [Payment::METHOD_CASH, Payment::METHOD_MANUAL], true)) {
            throw new PaymentException(
                'This payment is not a manual payment.',
                422,
                ['method' => $payment->method],
            );
        }

        if ($payment->status !== Payment::STATUS_PENDING) {
            throw new PaymentException(
                "Cannot approve payment in '{$payment->status}' status. Only 'pending' payments can be approved.",
                422,
                ['current_status' => $payment->status],
            );
        }

        $payment->update([
            'status'  => Payment::STATUS_COMPLETED,
            'paid_at' => now(),
        ]);

        $this->recordTransaction(
            payment: $payment,
            type: 'verify',
            status: 'success',
            request: null,
            response: [
                'approved_by' => $approver->id,
                'approved_at' => now()->toIso8601String(),
            ],
            error: null,
        );

        $this->auditLogger->log(
            $approver,
            action: 'payment.manual_approved',
            description: "Approved {$payment->method} payment {$payment->reference}.",
            subject: $payment,
            changes: [
                'amount'      => $payment->amount,
                'method'      => $payment->method,
                'approved_by' => $approver->id,
            ],
        );

        // Cascades to delivery.paid if all stages are complete.
        $this->markDeliveryPaidIfComplete($payment->delivery_id);

        try {
            $this->notifications->paymentCompleted($payment->fresh());
        } catch (\Throwable $e) {
            Log::error('Notification write after manual payment approval failed', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return $payment->fresh();
    }

    /**
     * Reject a manual payment with a required reason. Delivery stays
     * `pending_payment`; Distributor can retry with a fresh record.
     */
    public function rejectManualPayment(User $approver, Payment $payment, string $reason): Payment
    {
        if (! in_array($payment->method, [Payment::METHOD_CASH, Payment::METHOD_MANUAL], true)) {
            throw new PaymentException(
                'This payment is not a manual payment.',
                422,
                ['method' => $payment->method],
            );
        }

        if ($payment->status !== Payment::STATUS_PENDING) {
            throw new PaymentException(
                "Cannot reject payment in '{$payment->status}' status. Only 'pending' payments can be rejected.",
                422,
                ['current_status' => $payment->status],
            );
        }

        $payment->update([
            'status'         => Payment::STATUS_FAILED,
            'failed_at'      => now(),
            'failure_reason' => substr($reason, 0, 250),
        ]);

        $this->recordTransaction(
            payment: $payment,
            type: 'verify',
            status: 'failed',
            request: null,
            response: [
                'rejected_by' => $approver->id,
                'rejected_at' => now()->toIso8601String(),
            ],
            error: $reason,
        );

        $this->auditLogger->log(
            $approver,
            action: 'payment.manual_rejected',
            description: "Rejected {$payment->method} payment {$payment->reference}: {$reason}",
            subject: $payment,
            changes: [
                'amount'      => $payment->amount,
                'method'      => $payment->method,
                'reason'      => $reason,
                'rejected_by' => $approver->id,
            ],
        );

        try {
            $this->notifications->paymentFailed($payment->fresh());
        } catch (\Throwable $e) {
            Log::error('Notification write after manual payment rejection failed', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return $payment->fresh();
    }
    
    // ═════════════════════════════════════════════════════════
    // Sync (poll)
    // ═════════════════════════════════════════════════════════

    public function syncPayment(Payment $payment): Payment
    {
        if ($payment->isTerminal()) {
            return $payment;
        }

        $correlationId = (string) Str::uuid();

        try {
            $result = $this->client->queryPayment(
                $payment->reference,
                paymentId: $payment->id,
                correlationId: $correlationId,
            );
        } catch (ClickPesaException $e) {
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
    // State machine (public for ReconciliationService — 11.4)
    // ═════════════════════════════════════════════════════════

    /**
     * Apply a ClickPesa status to the local Payment, guarding against
     * invalid transitions. Returns true if state actually changed.
     *
     * @return bool True = state changed, false = no-op or rejected by terminal guard
     */
       public function applyStatus(Payment $payment, PaymentStatus $clickPesa, array $providerPayload): bool
    {
        $newStatus = PaymentStatusMapper::toPaymentStatus($clickPesa);
        if ($newStatus === null) {
            Log::info('ClickPesa returned UNKNOWN status; state untouched', [
                'payment_id' => $payment->id,
                'raw'        => $providerPayload,
            ]);
            return false;
        }

        $changed = false;
        $justCompleted = false;
        $justFailed = false;

        DB::transaction(function () use ($payment, $newStatus, $providerPayload, &$changed, &$justCompleted, &$justFailed) {
            /** @var Payment $locked */
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if (! PaymentStatusMapper::canTransition($locked->status, $newStatus)) {
                return;
            }

            $changes = ['status' => $newStatus];
            if ($newStatus === Payment::STATUS_COMPLETED) {
                $changes['paid_at'] = now();
                $changes['failure_reason'] = null;
                $justCompleted = true;
            } elseif ($newStatus === Payment::STATUS_FAILED) {
                $changes['failed_at'] = now();
                $changes['failure_reason'] = $this->extractFailureReason($providerPayload);
                $justFailed = true;
            }

            $locked->update($changes);
            $changed = true;

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

        // Phase 13.1 — fire notifications AFTER commit so the row exists
        // and can be linked from the notification's `data.delivery_id`.
        if ($changed) {
            try {
                $fresh = $payment->fresh();
                if ($justCompleted && $fresh !== null) {
                    $this->notifications->paymentCompleted($fresh);
                } elseif ($justFailed && $fresh !== null) {
                    $this->notifications->paymentFailed($fresh);
                }
            } catch (\Throwable $e) {
                Log::error('Notification write after payment status change failed', [
                    'payment_id' => $payment->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $changed;
    }

    /**
     * Append a PaymentTransaction row (public for ReconciliationService — 11.4).
     */
    public function recordTransaction(
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

    // ═════════════════════════════════════════════════════════
    // Internals
    // ═════════════════════════════════════════════════════════

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

                // Phase 13.1 — notify branch receivers the package is ready.
                try {
                    $this->notifications->packageReady($delivery);
                } catch (\Throwable $e) {
                    Log::error('Notification write after delivery paid failed', [
                        'delivery_id' => $delivery->id,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    
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