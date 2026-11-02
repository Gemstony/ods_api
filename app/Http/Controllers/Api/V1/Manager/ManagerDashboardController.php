<?php

namespace App\Http\Controllers\Api\V1\Manager;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manager-facing dashboard KPIs.
 *
 * Endpoint:
 *   GET /manager/dashboard  → operational snapshot scoped to own branch
 *
 * Focused on what a branch manager needs to run day-to-day operations:
 * the state of the delivery pipeline, today's throughput, and today's
 * revenue. Owner-level concerns (payables, disputes, cross-branch) are
 * not included.
 *
 * Response shape:
 *   {
 *     "branch":     { id, name, code, status } | null,
 *     "staff":      { total_active, receivers, riders },
 *     "deliveries": { today_count, active_count, waiting_pickup,
 *                     in_transit, delivered_today },
 *     "revenue":    { today_tsh },
 *     "generated_at": "..."
 *   }
 *
 * All delivery + revenue queries are scoped to `pickup_branch_id = manager.branch_id`.
 */
class ManagerDashboardController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $manager = $request->user();
        $branchId = $manager->branch_id;

        if ($branchId === null) {
            return $this->success(
                data: $this->emptyResponse(),
                message: 'OK',
            );
        }

        /** @var Branch|null $branch */
        $branch = Branch::find($branchId);
        if (!$branch) {
            return $this->success(
                data: $this->emptyResponse(),
                message: 'OK',
            );
        }

        $today = now()->startOfDay();

        // ─── Staff counts ─────────────────────────────────────
        // ─── Staff counts ─────────────────────────────────────
        // "Staff" = Receivers + Riders only. Excludes the manager
        // themselves and any other Managers assigned to this branch.
        // Without this role filter, `total_active` would include the
        // manager and disagree with `receivers + riders`.
        $staffBase = User::where('branch_id', $branchId)
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('role', fn($q) => $q->whereIn('name', [
                Role::RECEIVER,
                Role::RIDER,
            ]));
        $staffTotal = (clone $staffBase)->count();
        $receivers = (clone $staffBase)
            ->whereHas('role', fn($q) => $q->where('name', Role::RECEIVER))
            ->count();
        $riders = (clone $staffBase)
            ->whereHas('role', fn($q) => $q->where('name', Role::RIDER))
            ->count();

        // ─── Delivery counts (branch-scoped) ──────────────────
        $deliveriesBase = Delivery::where('pickup_branch_id', $branchId);

        $todayCount = (clone $deliveriesBase)
            ->where('created_at', '>=', $today)
            ->count();

        $deliveredToday = (clone $deliveriesBase)
            ->where('delivered_at', '>=', $today)
            ->count();

        $activeCount = (clone $deliveriesBase)
            ->whereNotIn('status', [
                Delivery::STATUS_DELIVERED,
                Delivery::STATUS_CANCELLED,
                Delivery::STATUS_FAILED,
                Delivery::STATUS_EXPIRED,
            ])
            ->count();

        // "Waiting for pickup" = paid but not yet assigned to a rider.
        // Action item for the manager: they need to trigger batching
        // or assign a rider manually.
        $waitingPickup = (clone $deliveriesBase)
            ->where('status', Delivery::STATUS_PAID)
            ->count();

        // "In transit" = rider has the package.
        $inTransit = (clone $deliveriesBase)
            ->whereIn('status', [
                Delivery::STATUS_PICKED_UP,
                Delivery::STATUS_DISPATCHED,
            ])
            ->count();

        // ─── Revenue today (branch-scoped) ────────────────────
        $revenueToday = (float) Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('paid_at', '>=', $today)
            ->whereHas(
                'delivery',
                fn($q) => $q->where('pickup_branch_id', $branchId),
            )
            ->sum('amount');

        return $this->success(
            data: [
                'branch' => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'status' => $branch->status,
                ],
                'staff' => [
                    'total_active' => $staffTotal,
                    'receivers' => $receivers,
                    'riders' => $riders,
                ],
                'deliveries' => [
                    'today_count' => $todayCount,
                    'active_count' => $activeCount,
                    'waiting_pickup' => $waitingPickup,
                    'in_transit' => $inTransit,
                    'delivered_today' => $deliveredToday,
                ],
                'revenue' => [
                    'today_tsh' => $revenueToday,
                ],
                'generated_at' => now()->toIso8601String(),
            ],
            message: 'OK',
        );
    }

    private function emptyResponse(): array
    {
        return [
            'branch' => null,
            'staff' => [
                'total_active' => 0,
                'receivers' => 0,
                'riders' => 0,
            ],
            'deliveries' => [
                'today_count' => 0,
                'active_count' => 0,
                'waiting_pickup' => 0,
                'in_transit' => 0,
                'delivered_today' => 0,
            ],
            'revenue' => ['today_tsh' => 0],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}