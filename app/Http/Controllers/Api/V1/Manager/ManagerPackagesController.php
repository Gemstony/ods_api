<?php

namespace App\Http\Controllers\Api\V1\Manager;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manager's package inventory for their own branch.
 *
 * Endpoint:
 *   GET /manager/packages
 *
 * "Packages at branch" = deliveries physically sitting at the branch,
 * waiting for a Rider to pick them up. Two statuses qualify:
 *   - paid     — payment complete, no rider yet
 *   - batched  — part of a batching group, still at branch
 *
 * Send deliveries are excluded because they originate from the
 * distributor's location, not from the branch.
 *
 * Each delivery carries a virtual `hours_at_branch` attribute,
 * computed server-side from the delivery's `created_at`. Ordered
 * oldest-first so aging packages surface at the top.
 */
class ManagerPackagesController extends Controller
{
    use ApiResponse;

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

        $packages = Delivery::with(['items'])
            ->where('pickup_branch_id', $branchId)
            ->whereIn('status', [
                Delivery::STATUS_PAID,
                Delivery::STATUS_BATCHED,
            ])
            ->where('service_type', '!=', Delivery::SERVICE_SEND)
            ->orderBy('created_at') //oldest first
            ->limit(100)
            ->get();

        // Attach `hours_at_branch` to each delivery before serializing.
        // Attach `hours_at_branch` to each delivery before serializing.
        //
        // Base timestamp = the delivery's `created_at`. The transition
        // `created_at` → `paid` is a few seconds (create-then-pay), so
        // for an hours-granularity metric the delta is negligible. When
        // finer precision is needed (e.g., Phase 11 with heavy payment
        // flows), this can join `payments.paid_at` instead.
        foreach ($packages as $pkg) {
            /** @var Delivery $pkg */
            $since = $pkg->created_at ?? now();
            $pkg->setAttribute(
                'hours_at_branch',
                max(0, (int) now()->diffInHours($since, absolute: true)),
            );
        }

        $items = [];
        foreach ($packages as $pkg) {
            $items[] = (new DeliveryResource($pkg))->toArray($request);
        }

        return $this->success(
            data: [
                'data' => $items,
                'total' => count($items),
                'per_page' => 100,
                'current_page' => 1,
                'last_page' => 1,
            ],
            message: 'OK',
        );
    }
}