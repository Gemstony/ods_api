<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Earning;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rider Home tab KPIs.
 *
 * Endpoint:
 *   GET /rider/dashboard
 *
 * Task buckets:
 *   available  → deliveries assigned to this rider, not yet accepted
 *   active     → accepted OR picked_up (in the rider's hands right now)
 *   delivered_* → historic counts (today / month / all time)
 *
 * Earnings totals come from the `earnings` table. That table is not
 * populated until Phase 11 (ClickPesa + compensation service), so
 * these values are 0 today. The queries are wrapped in try/catch so a
 * schema drift (e.g. the FK is `rider_id` not `user_id`) returns 0s
 * instead of 500-ing the whole dashboard.
 */
class RiderDashboardController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $riderId = $request->user()->id;
        $today = now()->startOfDay();
        $startOfMonth = now()->startOfMonth();

        $availableTasks = Delivery::where('rider_id', $riderId)
            ->where('status', Delivery::STATUS_ASSIGNED)
            ->count();

        $activeTasks = Delivery::where('rider_id', $riderId)
            ->whereIn('status', [
                Delivery::STATUS_ACCEPTED,
                Delivery::STATUS_PICKED_UP,
            ])
            ->count();

        $deliveredToday = Delivery::where('rider_id', $riderId)
            ->where('status', Delivery::STATUS_DELIVERED)
            ->where('delivered_at', '>=', $today)
            ->count();

        $deliveredThisMonth = Delivery::where('rider_id', $riderId)
            ->where('status', Delivery::STATUS_DELIVERED)
            ->where('delivered_at', '>=', $startOfMonth)
            ->count();

        $totalDelivered = Delivery::where('rider_id', $riderId)
            ->where('status', Delivery::STATUS_DELIVERED)
            ->count();

        // ─── Earnings (best-effort — schema TBD until Phase 11) ──
        // If your `earnings` FK is `rider_id` not `user_id`, change
        // 'user_id' below to 'rider_id'.
        $earningsThisMonth = 0.0;
        $earningsPending = 0.0;
        try {
            $earningsThisMonth = (float) Earning::where('user_id', $riderId)
                ->where('created_at', '>=', $startOfMonth)
                ->sum('amount');

            $earningsPending = (float) Earning::where('user_id', $riderId)
                ->whereIn('status', [
                    Earning::STATUS_PENDING,
                    Earning::STATUS_APPROVED,
                ])
                ->sum('amount');
        } catch (\Exception $e) {
            // Schema not settled; return zeros.
        }

        return $this->success(
            data: [
                'tasks' => [
                    'available' => $availableTasks,
                    'active' => $activeTasks,
                    'delivered_today' => $deliveredToday,
                    'delivered_this_month' => $deliveredThisMonth,
                    'total_delivered' => $totalDelivered,
                ],
                'earnings' => [
                    'this_month_tsh' => $earningsThisMonth,
                    'pending_tsh' => $earningsPending,
                ],
                'generated_at' => now()->toIso8601String(),
            ],
            message: 'OK',
        );
    }
}