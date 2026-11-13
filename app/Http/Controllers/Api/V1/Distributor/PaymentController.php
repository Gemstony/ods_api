<?php

namespace App\Http\Controllers\Api\V1\Distributor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Distributor\InitiatePaymentRequest;
use App\Models\Delivery;
use App\Models\Payment;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Distributor-facing payment endpoints (Phase 11.2).
 *
 *   POST /distributor/payments/initiate       → start a ClickPesa USSD push
 *   GET  /distributor/payments/{payment}      → current state (no provider call)
 *   POST /distributor/payments/{payment}/refresh → actively poll + reconcile
 *
 * Every action is scoped to the authenticated distributor — a
 * distributor can only see and act on payments for their own deliveries.
 */
class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentService $payments) {}

    // ─── initiate ─────────────────────────────────────────────
    public function initiate(InitiatePaymentRequest $request): JsonResponse
    {
        $user = $request->user();

        $delivery = Delivery::where('id', $request->integer('delivery_id'))
            ->where('distributor_id', $user->id)
            ->first();

        if ($delivery === null) {
            return $this->error('Delivery not found.', null, 404);
        }

        $phone = (string) ($request->input('phone_number') ?: $user->phone);
        $stage = (string) ($request->input('stage') ?: Payment::STAGE_FULL);

        try {
            $payment = $this->payments->initiatePayment(
                delivery: $delivery,
                initiator: $user,
                phoneNumber: $phone,
                stage: $stage,
            );
        } catch (PaymentException $e) {
            return $this->error(
                $e->getMessage(),
                $e->context,
                $e->httpStatus,
            );
        }

        return $this->success(
            data: $this->paymentPayload($payment, includeUssd: true),
            message: 'USSD prompt sent. Enter your PIN on your phone to complete payment.',
            status: 201,
        );
    }

    // ─── show ─────────────────────────────────────────────────
    public function show(Request $request, int $payment): JsonResponse
    {
        $model = $this->resolveOwnedPayment($request, $payment);
        if ($model instanceof JsonResponse) return $model;

        return $this->success(
            data: $this->paymentPayload($model),
            message: 'OK',
        );
    }

    // ─── refresh ──────────────────────────────────────────────
    public function refresh(Request $request, int $payment): JsonResponse
    {
        $model = $this->resolveOwnedPayment($request, $payment);
        if ($model instanceof JsonResponse) return $model;

        $fresh = $this->payments->syncPayment($model);

        return $this->success(
            data: $this->paymentPayload($fresh),
            message: 'Payment refreshed.',
        );
    }

    // ─── Helpers ──────────────────────────────────────────────
    private function resolveOwnedPayment(Request $request, int $paymentId): Payment|JsonResponse
    {
        $userId = $request->user()->id;

        $model = Payment::with('delivery')
            ->where('id', $paymentId)
            ->whereHas('delivery', fn ($q) => $q->where('distributor_id', $userId))
            ->first();

        if ($model === null) {
            return $this->error('Payment not found.', null, 404);
        }
        return $model;
    }

    private function paymentPayload(Payment $payment, bool $includeUssd = false): array
    {
        $delivery = $payment->relationLoaded('delivery')
            ? $payment->delivery
            : $payment->delivery()->first();

        $payload = [
            'id'                 => $payment->id,
            'delivery_id'        => $payment->delivery_id,
            'delivery_status'    => $delivery?->status,
            'stage'              => $payment->stage,
            'amount'             => (float) $payment->amount,
            'currency'           => $payment->currency,
            'method'             => $payment->method,
            'status'             => $payment->status,
            'reference'          => $payment->reference,
            'provider_reference' => $payment->provider_reference,
            'initiated_at'       => $payment->initiated_at?->toIso8601String(),
            'paid_at'            => $payment->paid_at?->toIso8601String(),
            'failed_at'          => $payment->failed_at?->toIso8601String(),
            'expires_at'         => $payment->expires_at?->toIso8601String(),
            'failure_reason'     => $payment->failure_reason,
        ];

        if ($includeUssd) {
            $raw = $payment->metadata['clickpesa_response'] ?? [];
            $payload['ussd'] = [
                'provider_status' => $raw['status'] ?? 'PENDING',
                'message'         => $raw['message']
                    ?? 'A USSD prompt has been sent to your phone. Enter your PIN to confirm.',
            ];
        }

        return $payload;
    }
}