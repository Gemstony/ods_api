<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentDetailResource;
use App\Models\Payment;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner-facing payment ledger (Phase 11.4).
 *
 *   GET /owner/payments              → paginated list
 *   GET /owner/payments/{payment}    → full detail (transactions, callbacks, API logs)
 */
class PaymentLedgerController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['delivery:id,tracking_id,status,service_type,distributor_id'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $raw = (string) $request->query('status');
            $requested = array_filter(array_map('trim', explode(',', $raw)));
            $valid = [
                Payment::STATUS_PENDING,
                Payment::STATUS_PROCESSING,
                Payment::STATUS_COMPLETED,
                Payment::STATUS_FAILED,
                Payment::STATUS_CANCELLED,
                Payment::STATUS_REFUNDED,
                Payment::STATUS_EXPIRED,
            ];
            $filtered = array_values(array_intersect($requested, $valid));
            if (! empty($filtered)) {
                $query->whereIn('status', $filtered);
            }
        }

        if ($request->filled('method')) {
            $method = (string) $request->query('method');
            if (in_array($method, [Payment::METHOD_CLICKPESA, Payment::METHOD_CASH, Payment::METHOD_MANUAL], true)) {
                $query->where('method', $method);
            }
        }

        if ($request->filled('delivery_id')) {
            $query->where('delivery_id', (int) $request->query('delivery_id'));
        }

        if ($request->filled('reference')) {
            $query->where('reference', 'like', '%' . $request->query('reference') . '%');
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to') . ' 23:59:59');
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;
        $paginated = $query->paginate($perPage);

        $items = $paginated->items();
        $mapped = [];
        foreach ($items as $payment) {
            $mapped[] = (new PaymentDetailResource($payment))->toArray($request);
        }

        return $this->success(
            data: [
                'data' => $mapped,
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
            message: 'OK',
        );
    }

    public function show(Request $request, int $payment): JsonResponse
    {
        $model = Payment::with([
            'delivery:id,tracking_id,status,service_type,distributor_id,total_amount',
            'initiatedBy:id,name,email',
            'transactions',
            'callbacks',
            'clickpesaApiLogs',
        ])->find($payment);

        if ($model === null) {
            return $this->error('Payment not found.', null, 404);
        }

        return $this->success(
            data: new PaymentDetailResource($model),
            message: 'OK',
        );
    }
}