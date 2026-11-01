<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Models\DispatcherTicket;
use App\Models\Earning;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Payout;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Owner-facing finance summary.
 *
 * Endpoint:
 *   GET /owner/finance/summary  → detailed ledger overview
 *
 * Complements `GET /owner/dashboard` (5.4) with a deeper financial
 * picture: revenue breakdown, outstanding payables by type, transport
 * money owed, this-month payouts, expenses, and the reconciliation
 * equation from the ODS document:
 *
 *   operating_surplus = revenue_this_month
 *                     − payouts_this_month
 *                     − transport_paid_this_month
 *                     − expenses_this_month
 *
 * Every figure is derived from a single authoritative table. No cached
 * totals — the whole point of a "current snapshot" is accuracy.
 */
class FinanceController extends Controller
{
    use ApiResponse;

    public function summary(): JsonResponse
    {
        $today = now()->startOfDay();
        $startOfWeek = now()->startOfWeek();
        $startOfMonth = now()->startOfMonth();

        // ─── Revenue (completed payments only) ────────────────
        $revenueQuery = Payment::where('status', Payment::STATUS_COMPLETED);
        $revenueToday = (float) (clone $revenueQuery)
            ->where('paid_at', '>=', $today)->sum('amount');
        $revenueWeek = (float) (clone $revenueQuery)
            ->where('paid_at', '>=', $startOfWeek)->sum('amount');
        $revenueMonth = (float) (clone $revenueQuery)
            ->where('paid_at', '>=', $startOfMonth)->sum('amount');
        $revenueAllTime = (float) (clone $revenueQuery)->sum('amount');

        // ─── Payables (money ODS owes, broken down) ───────────
        $riderTypes = [Earning::TYPE_RIDER_BASELINE, Earning::TYPE_RIDER_BONUS];
        $dispatcherTypes = [
            Earning::TYPE_DISPATCHER_FLAT,
            Earning::TYPE_DISPATCHER_SHIFT,
            Earning::TYPE_DISPATCHER_PERCENT,
        ];

        $riderPending = (float) Earning::whereIn('type', $riderTypes)
            ->where('status', Earning::STATUS_PENDING)->sum('amount');
        $riderApproved = (float) Earning::whereIn('type', $riderTypes)
            ->where('status', Earning::STATUS_APPROVED)->sum('amount');
        $dispatcherPending = (float) Earning::whereIn('type', $dispatcherTypes)
            ->where('status', Earning::STATUS_PENDING)->sum('amount');
        $dispatcherApproved = (float) Earning::whereIn('type', $dispatcherTypes)
            ->where('status', Earning::STATUS_APPROVED)->sum('amount');
        $transportOwed = (float) DispatcherTicket::whereNull('provider_confirmed_at')
            ->sum('transport_fare');

        // ─── Payouts (money that already left ODS) ────────────
        $paidPayouts = Payout::where('status', Payout::STATUS_PAID);
        $payoutsMonthCount = (int) (clone $paidPayouts)
            ->where('processed_at', '>=', $startOfMonth)->count();
        $payoutsMonthTsh = (float) (clone $paidPayouts)
            ->where('processed_at', '>=', $startOfMonth)->sum('amount');

        $pendingPayouts = Payout::whereIn('status', [
            Payout::STATUS_PENDING,
            Payout::STATUS_PROCESSING,
        ]);
        $payoutsPendingCount = (int) (clone $pendingPayouts)->count();
        $payoutsPendingTsh = (float) (clone $pendingPayouts)->sum('amount');

        // ─── Transport paid to providers this month ───────────
        $transportPaidMonth = (float) DispatcherTicket::whereNotNull('provider_confirmed_at')
            ->where('provider_confirmed_at', '>=', $startOfMonth)
            ->sum('transport_fare');

        // ─── Expenses this month ──────────────────────────────
        $expensesMonth = (float) Expense::where('occurred_at', '>=', $startOfMonth)
            ->sum('amount');
        $expensesAllTime = (float) Expense::sum('amount');

        // ─── Reconciliation (ODS formula) ─────────────────────
        $operatingSurplus = $revenueMonth
            - $payoutsMonthTsh
            - $transportPaidMonth
            - $expensesMonth;

        // ─── Recent payouts (last 5) ──────────────────────────
        $recentPayouts = Payout::with('user')
            ->orderByDesc('requested_at')
            ->limit(5)
            ->get()
            ->map(fn (Payout $p) => [
                'id' => $p->id,
                'user_name' => $p->user?->name ?? 'Unknown',
                'amount' => (float) $p->amount,
                'currency' => $p->currency,
                'status' => $p->status,
                'reference' => $p->reference,
                'requested_at' => $p->requested_at?->toIso8601String(),
                'processed_at' => $p->processed_at?->toIso8601String(),
            ])
            ->all();

        return $this->success(
            data: [
                'revenue' => [
                    'today_tsh' => $revenueToday,
                    'this_week_tsh' => $revenueWeek,
                    'this_month_tsh' => $revenueMonth,
                    'all_time_tsh' => $revenueAllTime,
                ],
                'payables' => [
                    'rider_pending_tsh' => $riderPending,
                    'rider_approved_tsh' => $riderApproved,
                    'dispatcher_pending_tsh' => $dispatcherPending,
                    'dispatcher_approved_tsh' => $dispatcherApproved,
                    'transport_fare_owed_tsh' => $transportOwed,
                ],
                'payouts' => [
                    'this_month_count' => $payoutsMonthCount,
                    'this_month_tsh' => $payoutsMonthTsh,
                    'pending_count' => $payoutsPendingCount,
                    'pending_tsh' => $payoutsPendingTsh,
                ],
                'transport_paid' => [
                    'this_month_tsh' => $transportPaidMonth,
                ],
                'expenses' => [
                    'this_month_tsh' => $expensesMonth,
                    'all_time_tsh' => $expensesAllTime,
                ],
                'reconciliation' => [
                    'revenue_this_month_tsh' => $revenueMonth,
                    'minus_payouts_this_month_tsh' => $payoutsMonthTsh,
                    'minus_transport_paid_this_month_tsh' => $transportPaidMonth,
                    'minus_expenses_this_month_tsh' => $expensesMonth,
                    'operating_surplus_tsh' => $operatingSurplus,
                ],
                'recent_payouts' => $recentPayouts,
                'generated_at' => now()->toIso8601String(),
            ],
            message: 'OK',
        );
    }
}