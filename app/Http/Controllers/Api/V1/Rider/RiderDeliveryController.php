<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Services\Audit\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rider-facing delivery endpoints.
 *
 * Every action is scoped to `rider_id = auth.id` — a rider can never
 * see, accept, pickup, or deliver another rider's delivery. This is
 * enforced in the WHERE clause, not just the middleware, because a
 * route ID is not something the middleware can scope by.
 *
 * State machine enforced:
 *   assigned   → accept    → accepted
 *   accepted   → pickup    → picked_up
 *   picked_up  → deliver   → delivered
 *
 * Any action against a delivery in the wrong state returns 422 with
 * a machine-readable `errors.current_status` / `errors.expected_status`
 * so the client can correct the UI.
 *
 * Verification-code gating (Phase 12) is intentionally NOT enforced
 * here — Phase 8.1 ships a plain status transition. When Phase 12
 * lands, the `deliver` method will additionally require a code.
 */
class RiderDeliveryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    // ─── index ────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $riderId = $request->user()->id;

        $query = Delivery::query()
            ->where('rider_id', $riderId)
            ->with(['items'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $raw = (string) $request->query('status');
            $requested = array_filter(array_map('trim', explode(',', $raw)));

            $valid = [
                Delivery::STATUS_ASSIGNED,
                Delivery::STATUS_ACCEPTED,
                Delivery::STATUS_PICKED_UP,
                Delivery::STATUS_DELIVERED,
                Delivery::STATUS_CANCELLED,
                Delivery::STATUS_FAILED,
                Delivery::STATUS_EXPIRED,
            ];
            $filtered = array_values(array_intersect($requested, $valid));
            if (! empty($filtered)) {
                $query->whereIn('status', $filtered);
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

    // ─── show ─────────────────────────────────────────────────
    public function show(Request $request, int $delivery): JsonResponse
    {
        $model = Delivery::with(['items'])
            ->where('rider_id', $request->user()->id)
            ->where('id', $delivery)
            ->first();

        if (! $model) {
            return $this->error('Delivery not found.', null, 404);
        }

        return $this->success(new DeliveryResource($model), 'OK');
    }

    // ─── accept ───────────────────────────────────────────────
    public function accept(Request $request, int $delivery): JsonResponse
    {
        return $this->transition(
            request: $request,
            deliveryId: $delivery,
            expectedStatus: Delivery::STATUS_ASSIGNED,
            newStatus: Delivery::STATUS_ACCEPTED,
            timestampField: 'accepted_at',
            auditAction: 'delivery.accepted',
            actionLabel: 'accepted',
        );
    }

    // ─── pickup ───────────────────────────────────────────────
    public function pickup(Request $request, int $delivery): JsonResponse
    {
        return $this->transition(
            request: $request,
            deliveryId: $delivery,
            expectedStatus: Delivery::STATUS_ACCEPTED,
            newStatus: Delivery::STATUS_PICKED_UP,
            timestampField: 'picked_up_at',
            auditAction: 'delivery.picked_up',
            actionLabel: 'picked up',
        );
    }

    // ─── deliver ──────────────────────────────────────────────
    public function deliver(Request $request, int $delivery): JsonResponse
    {
        return $this->transition(
            request: $request,
            deliveryId: $delivery,
            expectedStatus: Delivery::STATUS_PICKED_UP,
            newStatus: Delivery::STATUS_DELIVERED,
            timestampField: 'delivered_at',
            auditAction: 'delivery.delivered',
            actionLabel: 'delivered',
        );
    }

    // ─── Shared transition helper ─────────────────────────────
    private function transition(
        Request $request,
        int $deliveryId,
        string $expectedStatus,
        string $newStatus,
        string $timestampField,
        string $auditAction,
        string $actionLabel,
    ): JsonResponse {
        $riderId = $request->user()->id;

        // Scoped lookup: 404 for any delivery not owned by this rider.
        $delivery = Delivery::where('id', $deliveryId)
            ->where('rider_id', $riderId)
            ->first();

        if (! $delivery) {
            return $this->error('Delivery not found.', null, 404);
        }

        if ($delivery->status !== $expectedStatus) {
            return $this->error(
                "Cannot {$actionLabel} delivery: current status is '{$delivery->status}', expected '{$expectedStatus}'.",
                [
                    'current_status' => $delivery->status,
                    'expected_status' => $expectedStatus,
                ],
                422,
            );
        }

        $oldStatus = $delivery->status;
        $delivery->status = $newStatus;
        $delivery->{$timestampField} = now();
        $delivery->save();

        $this->auditLogger->logCurrentUser(
            action: $auditAction,
            description: "Delivery {$delivery->tracking_id} {$actionLabel} by rider.",
            subject: $delivery,
            changes: [
                'status' => ['from' => $oldStatus, 'to' => $newStatus],
            ],
        );

        $delivery->load(['items']);

        return $this->success(new DeliveryResource($delivery), 'OK');
    }
}