<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\VerificationCode;
use App\Services\Audit\AuditLogger;
use App\Services\Compensation\CompensationService;
use App\Services\Verification\VerificationCodeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Rider-facing delivery endpoints.
 *
 * Phase 12.2: `pickup` and `deliver` accept an optional `verification_code`.
 * The gate arms only when `isRequired()` is true (setting on + code issued).
 * On any verification failure, returns 422 with a machine-readable
 * `outcome` and `remaining_attempts`.
 */
class RiderDeliveryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CompensationService $compensation,
        private readonly VerificationCodeService $verification,
        private readonly \App\Services\Notifications\NotificationService $notifications,
    ) {
    }

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
            if (!empty($filtered)) {
                $query->whereIn('status', $filtered);
            }
        }

        if ($request->filled('service_type')) {
            $type = $request->query('service_type');
            if (
                in_array($type, [
                    Delivery::SERVICE_RECEIVE,
                    Delivery::SERVICE_SEND,
                    Delivery::SERVICE_REGIONAL,
                ], true)
            ) {
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
        $model = Delivery::with(['items'])
            ->where('rider_id', $request->user()->id)
            ->where('id', $delivery)
            ->first();

        if (!$model) {
            return $this->error('Delivery not found.', null, 404);
        }

        return $this->success(new DeliveryResource($model), 'OK');
    }

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
            requiresCodeType: VerificationCode::TYPE_DISTRIBUTOR,
        );
    }

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
            markRiderAvailable: true,
            requiresCodeType: VerificationCode::TYPE_RECIPIENT,
        );
    }

    private function transition(
        Request $request,
        int $deliveryId,
        string $expectedStatus,
        string $newStatus,
        string $timestampField,
        string $auditAction,
        string $actionLabel,
        bool $markRiderAvailable = false,
        ?string $requiresCodeType = null,
    ): JsonResponse {
        $riderId = $request->user()->id;

        $delivery = Delivery::where('id', $deliveryId)
            ->where('rider_id', $riderId)
            ->first();

        if (!$delivery) {
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

        // Phase 12.2 — verification gate.
        if ($requiresCodeType !== null && $this->verification->isRequired($requiresCodeType, $delivery)) {
            $verificationError = $this->verifyCode(
                request: $request,
                delivery: $delivery,
                codeType: $requiresCodeType,
                actionLabel: $actionLabel,
            );
            if ($verificationError !== null) {
                return $verificationError;
            }
        }

        $oldStatus = $delivery->status;
        $delivery->status = $newStatus;
        $delivery->{$timestampField} = now();
        $delivery->save();

        if ($markRiderAvailable) {
            $request->user()->markAvailable();

            try {
                $this->compensation->awardForDeliveredDelivery($delivery);
            } catch (\Throwable $e) {
                Log::error('Compensation failed after delivery completion', [
                    'delivery_id' => $delivery->id,
                    'tracking_id' => $delivery->tracking_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->auditLogger->logCurrentUser(
            action: $auditAction,
            description: "Delivery {$delivery->tracking_id} {$actionLabel} by rider.",
            subject: $delivery,
            changes: [
                'status' => ['from' => $oldStatus, 'to' => $newStatus],
            ],
        );

        try {
            match ($newStatus) {
                Delivery::STATUS_ACCEPTED => $this->notifications->deliveryAccepted($delivery),
                Delivery::STATUS_PICKED_UP => $this->notifications->deliveryPickedUp($delivery),
                Delivery::STATUS_DELIVERED => $this->notifications->deliveryDelivered($delivery),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Notification write after delivery transition failed', [
                'delivery_id' => $delivery->id,
                'status' => $newStatus,
                'error' => $e->getMessage(),
            ]);
        }

        $delivery->load(['items']);

        return $this->success(new DeliveryResource($delivery), 'OK');
    }

    /**
     * Phase 12.2 helper — inline verification attempt for one transition.
     * Returns `null` if verification passed; a 422 JsonResponse otherwise.
     */
    private function verifyCode(
        Request $request,
        Delivery $delivery,
        string $codeType,
        string $actionLabel,
    ): ?JsonResponse {
        $rawCode = (string) $request->input('verification_code', '');

        if ($rawCode === '') {
            return $this->error(
                "A verification code is required to {$actionLabel} this delivery.",
                [
                    'outcome' => 'no_code_provided',
                    'code_type' => $codeType,
                    'remaining_attempts' => $this->verification->maxAttempts(),
                ],
                422,
            );
        }

        $result = $this->verification->verify(
            $delivery,
            $codeType,
            $rawCode,
            $request->user(),
        );

        if (!$result->isSuccess()) {
            return $this->error(
                $result->outcome->message(),
                array_merge($result->toArray(), [
                    'code_type' => $codeType,
                ]),
                422,
            );
        }

        return null;
    }
}