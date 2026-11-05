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
 * Scoped to the authenticated rider via `earnings.user_id`. Filters to
 * rider-eligible types (`rider_baseline`, `rider_bonus`, `adjustment`),
 * so any historical dispatcher earnings don't leak into the rider view.
 *
 * Response shape:
 *   {
 *     data:    [ { id, delivery_id, delivery_tracking_id, type, amount,
 *                  currency, status, earned_at, approved_at, paid_at,
 *                  notes, metadata, created_at, updated_at } ],
 *     summary: { owed_tsh, paid_this_month_tsh, paid_total_tsh },
 *     total, per_page, current_page, last_page
 *   }
 *
 * `owed_tsh` = SUM(amount) where status in (pending, approved). Includes
 * negative adjustments (clawbacks reduce what you're owed).
 *
 * Earnings rows are populated by the Phase 11 compensation service — the
 * endpoint is safe to call today (returns an empty list and zero totals).
 */
class RiderEarningsController extends Controller
{
    use ApiResponse;

    private const RIDER_TYPES = [
        Earning::TYPE_RIDER_BASELINE,
        Earning::TYPE_RIDER_BONUS,
        Earning::TYPE_ADJUSTMENT,
    ];

    public function index(Request $request): JsonResponse
    {
        $riderId = $request->user()->id;

        $query = Earning::query()
            ->with(['delivery:id,tracking_id'])
            ->where('user_id', $riderId)
            ->whereIn('type', self::RIDER_TYPES)
            ->orderByDesc('earned_at')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $raw = (string) $request->query('status');
            $requested = array_filter(array_map('trim', explode(',', $raw)));

            $valid = [
                Earning::STATUS_PENDING,
                Earning::STATUS_APPROVED,
                Earning::STATUS_PAID,
                Earning::STATUS_CANCELLED,
            ];
            $filtered = array_values(array_intersect($requested, $valid));
            if (! empty($filtered)) {
                $query->whereIn('status', $filtered);
            }
        }

        $perPage = (int) $request->query('per_page', 50);
        $perPage = $perPage > 0 ? min($perPage, 100) : 50;
        $paginated = $query->paginate($perPage);

        $items = [];
        foreach ($paginated->items() as $earning) {
            /** @var Earning $earning */
            $items[] = [
                'id' => $earning->id,
                'user_id' => $earning->user_id,
                'delivery_id' => $earning->delivery_id,
                'delivery_tracking_id' => $earning->delivery?->tracking_id,
                'type' => $earning->type,
                'amount' => (float) $earning->amount,
                'currency' => $earning->currency,
                'status' => $earning->status,
                'earned_at' => $earning->earned_at?->toIso8601String(),
                'approved_at' => $earning->approved_at?->toIso8601String(),
                'paid_at' => $earning->paid_at?->toIso8601String(),
                'notes' => $earning->notes,
                'metadata' => $earning->metadata,
                'created_at' => $earning->created_at?->toIso8601String(),
                'updated_at' => $earning->updated_at?->toIso8601String(),
            ];
        }

        // Summary totals — separate builders to avoid mutating a shared
        // query (Laravel's where() mutates in place).
        $owedTotal = (float) Earning::where('user_id', $riderId)
            ->whereIn('type', self::RIDER_TYPES)
            ->whereIn('status', [
                Earning::STATUS_PENDING,
                Earning::STATUS_APPROVED,
            ])
            ->sum('amount');

        $paidThisMonth = (float) Earning::where('user_id', $riderId)
            ->whereIn('type', self::RIDER_TYPES)
            ->where('status', Earning::STATUS_PAID)
            ->where('paid_at', '>=', now()->startOfMonth())
            ->sum('amount');

        $paidTotal = (float) Earning::where('user_id', $riderId)
            ->whereIn('type', self::RIDER_TYPES)
            ->where('status', Earning::STATUS_PAID)
            ->sum('amount');

        return $this->success(
            data: [
                'data' => $items,
                'summary' => [
                    'owed_tsh' => $owedTotal,
                    'paid_this_month_tsh' => $paidThisMonth,
                    'paid_total_tsh' => $paidTotal,
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