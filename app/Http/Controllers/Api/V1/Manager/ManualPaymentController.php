<?php

namespace App\Http\Controllers\Api\V1\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Manager\RejectManualPaymentRequest;
use App\Models\Delivery;
use App\Models\Payment;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manager approval of manual payments (Phase 13.7).
 *
 *   GET  /manager/payments/pending                → list manual payments awaiting approval
 *   POST /manager/payments/{payment}/approve      → mark completed → delivery flows
 *   POST /manager/payments/{payment}/reject       → mark failed + reason
 *
 * Scope: Manager can only approve/reject payments for deliveries whose
 * `pickup_branch_id` matches their own `branch_id`. If a delivery has
 * no pickup_branch_id (Send — pickup from distributor location), the
 * Manager cannot see it; that path falls back to Owner via the
 * `Owner\ManualPaymentController` (files 6 + 7 below).
 */
class ManualPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;
        if ($branchId === null) {
            return $this->error(
                'Your account is not assigned to a branch.',
                null,
                403,
            );
        }

        $query = Payment::with([
            'delivery:id,tracking_id,status,service_type,pickup_branch_id',
            'initiatedBy:id,name,email',
        ])
            ->whereIn('method', [Payment::METHOD_CASH, Payment::METHOD_MANUAL])
            ->where('status', Payment::STATUS_PENDING)
            ->whereHas('delivery', fn ($q) => $q->where('pickup_branch_id', $branchId))
            ->orderBy('initiated_at');

        $paginated = $query->paginate(20);

        $items = [];
        foreach ($paginated->items() as $payment) {
            $items[] = [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'stage' => $payment->stage,
                'status' => $payment->status,
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
        $model = $this->resolveBranchPayment($request, $payment);
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
        $model = $this->resolveBranchPayment($request, $payment);
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

    private function resolveBranchPayment(Request $request, int $paymentId): Payment|JsonResponse
    {
        $branchId = $request->user()->branch_id;
        if ($branchId === null) {
            return $this->error(
                'Your account is not assigned to a branch.',
                null,
                403,
            );
        }

        $model = Payment::with('delivery')
            ->where('id', $paymentId)
            ->whereIn('method', [Payment::METHOD_CASH, Payment::METHOD_MANUAL])
            ->whereHas('delivery', fn ($q) => $q->where('pickup_branch_id', $branchId))
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
