<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Delivery;
use App\Models\DispatcherTicket;
use App\Models\Earning;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Owner-facing dashboard KPIs.
 *
 * Endpoint:
 *   GET /owner/dashboard  → aggregated platform metrics
 *
 * One endpoint returns everything the Home tab needs. Each KPI is a
 * cheap indexed query — total query count is ~15, all wrapped in a
 * single response. The alternative (multiple endpoints + client-side
 * aggregation) would multiply latency and force the client to know
 * about internal data shapes.
 */
class DashboardController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $today = now()->startOfDay();
        $startOfWeek = now()->startOfWeek();
        $startOfMonth = now()->startOfMonth();

        // ─── Branches ─────────────────────────────────────────
        $activeBranches = Branch::where('status', Branch::STATUS_ACTIVE)->count();

        // ─── Users ────────────────────────────────────────────
        $activeUsers = User::where('status', User::STATUS_ACTIVE)->count();
        $pendingApprovals = User::where('status', User::STATUS_PENDING)->count();
        $managers = User::where('status', User::STATUS_ACTIVE)
            ->ofRole(Role::MANAGER)->count();
        $riders = User::where('status', User::STATUS_ACTIVE)
            ->ofRole(Role::RIDER)->count();
        $receivers = User::where('status', User::STATUS_ACTIVE)
            ->ofRole(Role::RECEIVER)->count();
        $distributors = User::where('status', User::STATUS_ACTIVE)
            ->ofRole(Role::DISTRIBUTOR)->count();

        // ─── Deliveries ───────────────────────────────────────
        $deliveriesToday = Delivery::where('created_at', '>=', $today)->count();
        $deliveredToday = Delivery::where('delivered_at', '>=', $today)->count();
        $activeDeliveries = Delivery::whereNotIn('status', [
            Delivery::STATUS_DELIVERED,
            Delivery::STATUS_CANCELLED,
            Delivery::STATUS_FAILED,
            Delivery::STATUS_EXPIRED,
        ])->count();
        $deliveriesThisWeek = Delivery::where('created_at', '>=', $startOfWeek)->count();

        // ─── Revenue (completed payments only) ────────────────
        $revenueToday = (float) Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('paid_at', '>=', $today)
            ->sum('amount');
        $revenueThisWeek = (float) Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('paid_at', '>=', $startOfWeek)
            ->sum('amount');
        $revenueThisMonth = (float) Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('paid_at', '>=', $startOfMonth)
            ->sum('amount');

        // ─── Payables (money owed, not yet paid out) ──────────
        $riderEarningsPending = (float) Earning::whereIn('type', [
            Earning::TYPE_RIDER_BASELINE,
            Earning::TYPE_RIDER_BONUS,
        ])->whereIn('status', [
            Earning::STATUS_PENDING,
            Earning::STATUS_APPROVED,
        ])->sum('amount');

        $dispatcherEarningsPending = (float) Earning::whereIn('type', [
            Earning::TYPE_DISPATCHER_FLAT,
            Earning::TYPE_DISPATCHER_SHIFT,
            Earning::TYPE_DISPATCHER_PERCENT,
        ])->whereIn('status', [
            Earning::STATUS_PENDING,
            Earning::STATUS_APPROVED,
        ])->sum('amount');

        $transportFareOwed = (float) DispatcherTicket::whereNull('provider_confirmed_at')
            ->sum('transport_fare');

        // ─── Disputes ─────────────────────────────────────────
        $openDisputes = \App\Models\Dispute::open()->count();

        return $this->success(
            data: [
                'branches' => [
                    'total_active' => $activeBranches,
                ],
                'users' => [
                    'total_active' => $activeUsers,
                    'pending_approvals' => $pendingApprovals,
                    'managers' => $managers,
                    'riders' => $riders,
                    'receivers' => $receivers,
                    'distributors' => $distributors,
                ],
                'deliveries' => [
                    'today_count' => $deliveriesToday,
                    'today_delivered' => $deliveredToday,
                    'active_count' => $activeDeliveries,
                    'this_week_count' => $deliveriesThisWeek,
                ],
                'revenue' => [
                    'today_tsh' => $revenueToday,
                    'this_week_tsh' => $revenueThisWeek,
                    'this_month_tsh' => $revenueThisMonth,
                ],
                'payables' => [
                    'rider_earnings_pending_tsh' => $riderEarningsPending,
                    'dispatcher_earnings_pending_tsh' => $dispatcherEarningsPending,
                    'transport_fare_owed_tsh' => $transportFareOwed,
                ],
                'disputes' => [
                    'open_count' => $openDisputes,
                ],
                'generated_at' => now()->toIso8601String(),
            ],
            message: 'OK',
        );
    }
}