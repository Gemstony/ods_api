<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rider earnings ledger.
 *
 * Endpoint:
 *   GET /rider/earnings
 *
 * Raw list of `earnings` rows scoped to this rider, plus a `summary`
 * block. Earnings rows do not exist until Phase 11 wires the
 * compensation service; the endpoint is safe to call today (returns
 * an empty list and zero totals).
 *
 * Note: the FK is assumed to be `earnings.user_id`. If your schema
 * uses `rider_id`, change both `where('user_id', ...)` calls below.
 */
class RiderEarningsController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $riderId = $request->user()->id;

        $query = Earning::where('user_id', $riderId)
            ->orderByDesc('created_at');

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;
        $paginated = $query->paginate($perPage);

        $items = [];
        foreach ($paginated->items() as $earning) {
            $items[] = [
                'id' => $earning->id,
                'delivery_id' => $earning->delivery_id,
                'type' => $earning->type,
                'amount' => (float) $earning->amount,
                'status' => $earning->status,
                'paid_at' => $earning->paid_at?->toIso8601String(),
                'created_at' => $earning->created_at?->toIso8601String(),
            ];
        }

        $pendingTotal = (float) Earning::where('user_id', $riderId)
            ->whereIn('status', [
                Earning::STATUS_PENDING,
                Earning::STATUS_APPROVED,
            ])
            ->sum('amount');

        return $this->success(
            data: [
                'data' => $items,
                'summary' => [
                    'pending_tsh' => $pendingTotal,
                ],
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
            message: 'OK',
        );
    }
}