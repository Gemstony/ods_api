<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutResource;
use App\Models\Payout;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner-facing payout ledger (Phase 11.4).
 *
 *   GET /owner/payouts             → paginated list, filterable
 *   GET /owner/payouts/{payout}    → single payout (with earnings if requested)
 */
class PayoutLedgerController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Payout::with(['user:id,name,email'])
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $raw = (string) $request->query('status');
            $requested = array_filter(array_map('trim', explode(',', $raw)));
            $valid = [
                Payout::STATUS_PENDING,
                Payout::STATUS_PROCESSING,
                Payout::STATUS_PAID,
                Payout::STATUS_FAILED,
                Payout::STATUS_CANCELLED,
            ];
            $filtered = array_values(array_intersect($requested, $valid));
            if (! empty($filtered)) {
                $query->whereIn('status', $filtered);
            }
        }

        if ($request->filled('method')) {
            $method = (string) $request->query('method');
            if (in_array($method, [Payout::METHOD_MOBILE, Payout::METHOD_BANK, Payout::METHOD_CASH], true)) {
                $query->where('method', $method);
            }
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        if ($request->filled('from')) {
            $query->where('requested_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('requested_at', '<=', $request->query('to') . ' 23:59:59');
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;
        $paginated = $query->paginate($perPage);

        $mapped = [];
        foreach ($paginated->items() as $payout) {
            $mapped[] = (new PayoutResource($payout))->toArray($request);
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

    public function show(Request $request, int $payout): JsonResponse
    {
        $model = Payout::with([
            'user:id,name,email',
            'processedBy:id,name',
            'earnings:id,payout_id,user_id,delivery_id,type,amount,status',
        ])->find($payout);

        if ($model === null) {
            return $this->error('Payout not found.', null, 404);
        }

        return $this->success(
            data: new PayoutResource($model),
            message: 'OK',
        );
    }
}