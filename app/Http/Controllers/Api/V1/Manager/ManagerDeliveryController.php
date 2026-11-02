<?php

namespace App\Http\Controllers\Api\V1\Manager;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manager's branch-scoped delivery oversight.
 *
 * Endpoints:
 *   GET /manager/deliveries          → paginated list, filterable
 *   GET /manager/deliveries/{id}     → one delivery (branch-scoped)
 *
 * Scope: deliveries where `pickup_branch_id = manager.branch_id`.
 * Regional deliveries always have a pickup_branch_id. Receive
 * deliveries also do. Send deliveries don't (they originate from the
 * distributor's location) — managers see them via future distribution
 * tracking, not here.
 */
class ManagerDeliveryController extends Controller
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

        $query = Delivery::query()
            ->where('pickup_branch_id', $branchId)
            ->with(['items'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $status = $request->query('status');
            if (in_array($status, [
                Delivery::STATUS_PENDING_PAYMENT,
                Delivery::STATUS_PAID,
                Delivery::STATUS_BATCHED,
                Delivery::STATUS_ASSIGNED,
                Delivery::STATUS_ACCEPTED,
                Delivery::STATUS_PICKED_UP,
                Delivery::STATUS_DISPATCHED,
                Delivery::STATUS_DELIVERED,
                Delivery::STATUS_CANCELLED,
                Delivery::STATUS_FAILED,
                Delivery::STATUS_EXPIRED,
            ], true)) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('service_type')) {
            $type = $request->query('service_type');
            if (in_array($type, [
                Delivery::SERVICE_RECEIVE,
                Delivery::SERVICE_SEND,
                Delivery::SERVICE_REGIONAL,
            ], true)) {
                $query->where('service_type', $type);
            }
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;
        $paginated = $query->paginate($perPage);

        $items = [];
        foreach ($paginated->items() as $delivery) {
            $items[] = (new DeliveryResource($delivery))->toArray($request);
        }

        return $this->success(
            data: [
                'data' => $items,
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
            message: 'OK',
        );
    }

    public function show(Request $request, int $delivery): JsonResponse
    {
        $branchId = $request->user()->branch_id;
        if ($branchId === null) {
            return $this->error(
                'Your account is not assigned to a branch.',
                null,
                403,
            );
        }

        $model = Delivery::with(['items'])
            ->where('pickup_branch_id', $branchId)
            ->where('id', $delivery)
            ->first();

        if (! $model) {
            return $this->error('Delivery not found.', null, 404);
        }

        return $this->success(new DeliveryResource($model), 'OK');
    }
}