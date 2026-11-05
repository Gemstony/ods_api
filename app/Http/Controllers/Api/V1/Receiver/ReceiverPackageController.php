<?php

namespace App\Http\Controllers\Api\V1\Receiver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Services\Audit\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receiver-facing package handover endpoints.
 *
 * Scope: `receive` deliveries at the receiver's branch. Send never
 * passes through a branch; Regional is handled by the RegionalDispatcher
 * controller (Phase 9.1 second half).
 *
 * Endpoints:
 *   GET  /receiver/packages                  → index (branch-scoped)
 *   GET  /receiver/packages/{delivery}       → show
 *   POST /receiver/packages/{delivery}/verify → verify (readiness check)
 *   POST /receiver/packages/{delivery}/release → release (accepted → picked_up)
 *
 * Handover contract (Decision A):
 *   - Receiver release AND rider pickup both transition accepted → picked_up.
 *   - First to arrive wins. The second sees `accepted ≠ current` and 422s.
 *   - Audit logs record the actor distinctly:
 *       delivery.released_by_receiver  (this controller)
 *       delivery.picked_up             (RiderDeliveryController, 8.1)
 *
 * Phase 12 will add a verification code gate inside `evaluateRelease`.
 */
class ReceiverPackageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    // ─── index ────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        if ($branchId instanceof JsonResponse) return $branchId;

        $query = Delivery::query()
            ->where('pickup_branch_id', $branchId)
            ->where('service_type', Delivery::SERVICE_RECEIVE)
            ->with(['items', 'rider:id,name,phone'])
            ->orderByDesc('created_at');

        $defaultStatuses = [
            Delivery::STATUS_PAID,
            Delivery::STATUS_BATCHED,
            Delivery::STATUS_ASSIGNED,
            Delivery::STATUS_ACCEPTED,
        ];

        $requested = $request->filled('status')
            ? array_filter(array_map('trim', explode(',', (string) $request->query('status'))))
            : $defaultStatuses;

        $valid = [
            Delivery::STATUS_PENDING_PAYMENT,
            Delivery::STATUS_PAID,
            Delivery::STATUS_BATCHED,
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
        $model = $this->resolvePackage($request, $delivery);
        if ($model instanceof JsonResponse) return $model;

        return $this->success(new DeliveryResource($model), 'OK');
    }

    // ─── verify ───────────────────────────────────────────────
    public function verify(Request $request, int $delivery): JsonResponse
    {
        $model = $this->resolvePackage($request, $delivery);
        if ($model instanceof JsonResponse) return $model;

        [$canRelease, $reason] = $this->evaluateRelease($model);

        return $this->success(
            data: [
                'delivery' => (new DeliveryResource($model))->toArray($request),
                'can_release' => $canRelease,
                'reason' => $reason,
                'rider' => $model->rider ? [
                    'id' => $model->rider->id,
                    'name' => $model->rider->name,
                    'phone' => $model->rider->phone,
                ] : null,
            ],
            message: 'OK',
        );
    }

    // ─── release ──────────────────────────────────────────────
    public function release(Request $request, int $delivery): JsonResponse
    {
        $model = $this->resolvePackage($request, $delivery);
        if ($model instanceof JsonResponse) return $model;

        [$canRelease, $reason] = $this->evaluateRelease($model);
        if (! $canRelease) {
            return $this->error(
                $reason ?? 'Package cannot be released.',
                ['current_status' => $model->status],
                422,
            );
        }

        $oldStatus = $model->status;
        $model->status = Delivery::STATUS_PICKED_UP;
        $model->picked_up_at = now();
        $model->save();

        $this->auditLogger->logCurrentUser(
            action: 'delivery.released_by_receiver',
            description: "Released package {$model->tracking_id} to rider.",
            subject: $model,
            changes: [
                'status' => ['from' => $oldStatus, 'to' => Delivery::STATUS_PICKED_UP],
                'rider_id' => $model->rider_id,
            ],
        );

        $model->load(['items', 'rider:id,name,phone']);

        return $this->success(
            new DeliveryResource($model),
            'Package released to rider.',
        );
    }

    // ─── Helpers ──────────────────────────────────────────────
    private function branchId(Request $request): int|JsonResponse
    {
        $id = $request->user()->branch_id;
        if ($id === null) {
            return $this->error(
                'Your account is not assigned to a branch.',
                null,
                403,
            );
        }
        return (int) $id;
    }

    private function resolvePackage(Request $request, int $id): Delivery|JsonResponse
    {
        $branchId = $this->branchId($request);
        if ($branchId instanceof JsonResponse) return $branchId;

        $model = Delivery::with(['items', 'rider:id,name,phone'])
            ->where('pickup_branch_id', $branchId)
            ->where('service_type', Delivery::SERVICE_RECEIVE)
            ->where('id', $id)
            ->first();

        if (! $model) {
            return $this->error('Package not found.', null, 404);
        }
        return $model;
    }

    /**
     * Gate for `release`. Phase 12 will insert a code-match check here;
     * for 9.1, readiness = correct status + rider assigned.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function evaluateRelease(Delivery $delivery): array
    {
        if ($delivery->status !== Delivery::STATUS_ACCEPTED) {
            return [
                false,
                "Package is currently '{$delivery->status}', not 'accepted'.",
            ];
        }
        if ($delivery->rider_id === null) {
            return [false, 'No rider is assigned to this package yet.'];
        }
        return [true, null];
    }
}