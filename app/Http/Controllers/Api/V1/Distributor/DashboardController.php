<?php

namespace App\Http\Controllers\Api\V1\Distributor;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\PricingConfig;
use App\Models\Reward;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Distributor-facing dashboard KPIs.
 *
 * Endpoint:
 *   GET /distributor/dashboard  → customer's own-account snapshot
 *
 * Everything the Distributor's Home tab needs in one call:
 *   - delivery counts (active, awaiting payment, delivered this month)
 *   - spend this month (paid deliveries only)
 *   - rewards progress + thresholds
 *   - last 3 deliveries (for the "recent activity" preview)
 *
 * Scoped strictly to `distributor_id = auth.id` — no cross-distributor
 * visibility.
 */
class DashboardController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $startOfMonth = now()->startOfMonth();
        $config = PricingConfig::current();

        $baseQuery = Delivery::where('distributor_id', $user->id);

        // ─── Delivery counts ──────────────────────────────────
        $activeStatuses = [
            Delivery::STATUS_PAID,
            Delivery::STATUS_BATCHED,
            Delivery::STATUS_ASSIGNED,
            Delivery::STATUS_ACCEPTED,
            Delivery::STATUS_PICKED_UP,
            Delivery::STATUS_DISPATCHED,
        ];

        $activeCount = (clone $baseQuery)
            ->whereIn('status', $activeStatuses)
            ->count();

        $awaitingPayment = (clone $baseQuery)
            ->where('status', Delivery::STATUS_PENDING_PAYMENT)
            ->count();

        $deliveredThisMonth = (clone $baseQuery)
            ->where('status', Delivery::STATUS_DELIVERED)
            ->where('delivered_at', '>=', $startOfMonth)
            ->count();

        $totalThisMonth = (clone $baseQuery)
            ->where('created_at', '>=', $startOfMonth)
            ->count();

        // ─── Spend this month ─────────────────────────────────
        // "Spent" = money actually paid and not refunded. Excludes
        // pending (not yet charged) and cancelled/failed/expired
        // (money returned or never collected).
        $spendStatuses = array_merge(
            $activeStatuses,
            [Delivery::STATUS_DELIVERED],
        );
        $spentThisMonth = (float) (clone $baseQuery)
            ->whereIn('status', $spendStatuses)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('total_amount');

        // ─── Rewards ──────────────────────────────────────────
        $reward = Reward::for($user);

        // ─── Recent deliveries (last 3) ──────────────────────
        $recent = (clone $baseQuery)
            ->with(['items'])
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        $recentItems = [];
        foreach ($recent as $d) {
            $recentItems[] = (new DeliveryResource($d))->toArray($request);
        }

        return $this->success(
            data: [
                'deliveries' => [
                    'active_count'            => $activeCount,
                    'awaiting_payment_count'  => $awaitingPayment,
                    'delivered_this_month'    => $deliveredThisMonth,
                    'total_this_month'        => $totalThisMonth,
                ],
                'revenue' => [
                    'spent_this_month_tsh' => $spentThisMonth,
                ],
                'rewards' => [
                    'progress'   => $reward->toArray(),
                    'thresholds' => [
                        'tier1'                 => (int) $config->reward_tier1_threshold,
                        'tier2'                 => (int) $config->reward_tier2_threshold,
                        'tier1_discount_percent'=> (float) $config->reward_tier1_discount_percent,
                    ],
                ],
                'recent_deliveries' => $recentItems,
                'generated_at'      => now()->toIso8601String(),
            ],
            message: 'OK',
        );
    }
}