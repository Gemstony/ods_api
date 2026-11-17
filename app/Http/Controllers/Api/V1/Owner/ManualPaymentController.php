<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Manager\RejectManualPaymentRequest;
use App\Models\Payment;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner fallback for manual payment approval (Phase 13.7).
 *
 * Handles:
 *   - Send deliveries (no pickup_branch_id → Manager has no visibility)
 *   - Any delivery where the Manager is unavailable
 *
 * Same endpoints and behaviour as Manager\ManualPaymentController, but
 * without branch scoping.
 */
class ManualPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $query = Payment::with([
            'delivery:id,tracking_id,status,service_type,pickup_branch_id',
            'initiatedBy:id,name,email',
        ])
            ->whereIn('method', [Payment::METHOD_CASH, Payment::METHOD_MANUAL])
            ->where('status', Payment::STATUS_PENDING)
            ->orderBy('initiated_at');

        $paginated = $query->paginate(50);

        $items = [];
        foreach ($paginated->items() as $payment) {
            $items[] = [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'stage' => $payment->stage,
                'initiated_at' => $payment->initiated_at?->toIso8601String(),
                'delivery' => $payment->delivery === null ? null : [
                    'id' => $payment->delivery->id,
                    'tracking_id' => $payment->delivery->tracking_id,
                    'service_type' => $payment->delivery->service_type,
                    'status' => $payment->delivery->status,
                ],
                'initiated_by' => $payment->initiatedBy === null ? null : [
                    'id' => $payment->initiatedBy->id,
                    'name' => $payment->initiatedBy->name,
                ],
            ];
        }

        return $this->success(
            data: [
                'data' => $items,
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
            message: 'OK',
        );
    }

    public function approve(Request $request, int $payment): JsonResponse
    {
        $model = $this->resolvePayment($payment);
        if ($model instanceof JsonResponse) {
            return $model;
        }

        try {
            $approved = $this->payments->approveManualPayment(
                $request->user(),
                $model,
            );
        } catch (PaymentException $e) {
            return $this->error($e->getMessage(), $e->context, $e->httpStatus);
        }

        return $this->success(
            data: $this->payload($approved),
            message: 'Manual payment approved.',
        );
    }

    public function reject(RejectManualPaymentRequest $request, int $payment): JsonResponse
    {
        $model = $this->resolvePayment($payment);
        if ($model instanceof JsonResponse) {
            return $model;
        }

        try {
            $rejected = $this->payments->rejectManualPayment(
                $request->user(),
                $model,
                (string) $request->input('reason'),
            );
        } catch (PaymentException $e) {
            return $this->error($e->getMessage(), $e->context, $e->httpStatus);
        }

        return $this->success(
            data: $this->payload($rejected),
            message: 'Manual payment rejected.',
        );
    }

    private function resolvePayment(int $paymentId): Payment|JsonResponse
    {
        $model = Payment::with('delivery')
            ->where('id', $paymentId)
            ->whereIn('method', [Payment::METHOD_CASH, Payment::METHOD_MANUAL])
            ->first();

        if ($model === null) {
            return $this->error('Payment not found.', null, 404);
        }

        return $model;
    }

    private function payload(Payment $payment): array
    {
        $delivery = $payment->delivery;

        return [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'method' => $payment->method,
            'stage' => $payment->stage,
            'initiated_by' => $payment->initiated_by,  // ← was missing
            'status' => $payment->status,
            'failure_reason' => $payment->failure_reason,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'failed_at' => $payment->failed_at?->toIso8601String(),
            'delivery' => $delivery === null ? null : [
                'id' => $delivery->id,
                'tracking_id' => $delivery->tracking_id,
                'status' => $delivery->status,
            ],
        ];
    }
}
