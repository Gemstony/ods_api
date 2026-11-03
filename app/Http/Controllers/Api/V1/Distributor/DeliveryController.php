<?php

namespace App\Http\Controllers\Api\V1\Distributor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Distributor\PricePreviewRequest;
use App\Http\Requests\Api\V1\Distributor\StoreDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Branch;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Services\Audit\AuditLogger;
use App\Services\Pricing\PricingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Distributor-facing delivery endpoints.
 *
 * Routes:
 *   GET  /distributor/deliveries                → index (own only)
 *   POST /distributor/deliveries                → store
 *   GET  /distributor/deliveries/{delivery}     → show (own only)
 *   POST /distributor/deliveries/price-preview  → pricePreview
 *
 * Every delivery belongs to the authenticated distributor. There is no
 * cross-distributor access — the "own only" filter is enforced by the
 * controller, not just middleware, because a Distributor ID isn't
 * something the router can scope by.
 *
 * Creation flow:
 *   1. Client may call `pricePreview` first to see the fee.
 *   2. Client calls `store` with the full payload.
 *   3. Server computes the authoritative price, snapshots it on the
 *      delivery, and creates the delivery in `pending_payment` status.
 *   4. Client pays (Phase 11) → delivery moves to `paid`.
 */
class DeliveryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PricingService $pricing,
        private readonly AuditLogger $auditLogger,
    ) {}

    // ─── index ────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $distributorId = $request->user()->id;

        $query = Delivery::with(['items'])
            ->where('distributor_id', $distributorId)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $status = $request->query('status');
            $validStatuses = [
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
            ];
            if (in_array($status, $validStatuses, true)) {
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

        // Pagination: support both `limit/offset` (used by Flutter
        // 4.4 repo) and `per_page/page` (Laravel standard).
        $perPage = (int) $request->query('per_page', 0);
        $page = (int) $request->query('page', 1);
        if ($perPage > 0) {
            $perPage = min($perPage, 100);
        } else {
            $limit = (int) $request->query('limit', 20);
            $perPage = $limit > 0 ? min($limit, 100) : 20;
            $offset = (int) $request->query('offset', 0);
            $page = intdiv($offset, max(1, $perPage)) + 1;
        }

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

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
            ->where('distributor_id', $request->user()->id)
            ->where('id', $delivery)
            ->first();

        if (! $model) {
            return $this->error('Delivery not found.', null, 404);
        }

        return $this->success(new DeliveryResource($model), 'OK');
    }

    // ─── pricePreview ─────────────────────────────────────────
    public function pricePreview(PricePreviewRequest $request): JsonResponse
    {
        $pricing = $this->computePricing(
            serviceType: $request->input('service_type'),
            pickupBranchId: $request->input('pickup_branch_id'),
            pickupLat: $request->input('pickup_latitude'),
            pickupLng: $request->input('pickup_longitude'),
            destinationLat: $request->input('destination_latitude'),
            destinationLng: $request->input('destination_longitude'),
            busCargoFare: (float) ($request->input('bus_cargo_fare') ?? 0),
            // apply_reward is accepted but ignored until Phase 13.
        );

        return $this->success(
            data: [
                'service_type' => $request->input('service_type'),
                ...$pricing,
            ],
            message: 'OK',
        );
    }

    // ─── store ────────────────────────────────────────────────
    public function store(StoreDeliveryRequest $request): JsonResponse
    {
        $distributor = $request->user();
        $serviceType = $request->input('service_type');

        $pricing = $this->computePricing(
            serviceType: $serviceType,
            pickupBranchId: $request->input('pickup_branch_id'),
            pickupLat: $request->input('pickup_latitude'),
            pickupLng: $request->input('pickup_longitude'),
            destinationLat: $request->input('destination_latitude'),
            destinationLng: $request->input('destination_longitude'),
            busCargoFare: (float) ($request->input('bus_cargo_fare') ?? 0),
        );

        $delivery = DB::transaction(function () use ($request, $distributor, $serviceType, $pricing) {
            $delivery = Delivery::create([
                'distributor_id' => $distributor->id,
                'service_type' => $serviceType,
                'status' => Delivery::STATUS_PENDING_PAYMENT,

                'pickup_branch_id' => $request->input('pickup_branch_id'),
                'pickup_address' => $request->input('pickup_address'),
                'pickup_latitude' => $request->input('pickup_latitude'),
                'pickup_longitude' => $request->input('pickup_longitude'),
                'pickup_contact_phone' => $request->input('pickup_contact_phone'),

                'destination_address' => $request->input('destination_address'),
                'destination_latitude' => $request->input('destination_latitude'),
                'destination_longitude' => $request->input('destination_longitude'),
                'destination_region' => $request->input('destination_region'),
                'destination_district' => $request->input('destination_district'),
                'destination_bus_stop' => $request->input('destination_bus_stop'),

                'recipient_name' => $request->input('recipient_name'),
                'recipient_phone' => $request->input('recipient_phone'),

                // Pricing snapshot from PricingService
                'distance_km' => $pricing['distance_km'],
                'rate_per_km' => $pricing['rate_per_km'],
                'minimum_fee' => $pricing['minimum_fee'],
                'rounding_increment' => $pricing['rounding_increment'],
                'calculated_fee' => $pricing['calculated_fee'],
                'ods_delivery_fee' => $pricing['ods_delivery_fee'],
                'bus_cargo_fare' => $pricing['bus_cargo_fare'],
                'discount_amount' => $pricing['discount_amount'],
                'discount_reason' => $pricing['discount_reason'],
                'total_amount' => $pricing['total_amount'],
                'currency' => $pricing['currency'],

                'notes' => $request->input('notes'),
            ]);

            foreach ($request->input('items', []) as $i => $item) {
                DeliveryItem::create([
                    'delivery_id' => $delivery->id,
                    'exgo_order_id' => $item['exgo_order_id'] ?? null,
                    'product_name' => $item['product_name'] ?? null,
                    'product_code' => $item['product_code'] ?? null,
                    'quantity' => $item['quantity'] ?? null,
                    'notes' => $item['notes'] ?? null,
                    'sort_order' => $i,
                ]);
            }

            return $delivery;
        });

        $this->auditLogger->logCurrentUser(
            action: 'delivery.created',
            description: "Created {$serviceType} delivery {$delivery->tracking_id}",
            subject: $delivery,
            changes: [
                'service_type' => $serviceType,
                'total_amount' => $pricing['total_amount'],
                'item_count' => count($request->input('items', [])),
            ],
        );

        $delivery->load(['items']);

        return $this->success(
            data: new DeliveryResource($delivery),
            message: 'Delivery request created. Complete payment to confirm.',
            status: 201,
        );
    }

    // ─── Helpers ──────────────────────────────────────────────
    /**
     * Computes pricing for any service type.
     *
     * Distance resolution per service:
     *   Receive → branch GPS → destination GPS
     *   Send    → pickup GPS → destination GPS
     *   Regional → no distance (base fee model)
     */
    private function computePricing(
        string $serviceType,
        ?int $pickupBranchId,
        mixed $pickupLat,
        mixed $pickupLng,
        mixed $destinationLat,
        mixed $destinationLng,
        float $busCargoFare,
    ): array {
        if ($serviceType === Delivery::SERVICE_REGIONAL) {
            return $this->pricing->previewRegional($busCargoFare);
        }

        // Local: compute distance
        if ($serviceType === Delivery::SERVICE_RECEIVE) {
            // Pickup is the branch
            $branch = Branch::findOrFail($pickupBranchId);
            $startLat = (float) $branch->latitude;
            $startLng = (float) $branch->longitude;
        } else {
            // Send: pickup is distributor's specified location
            $startLat = (float) $pickupLat;
            $startLng = (float) $pickupLng;
        }

        $distanceKm = $this->pricing->haversineKm(
            $startLat,
            $startLng,
            (float) $destinationLat,
            (float) $destinationLng,
        );

        return $this->pricing->previewLocal($distanceKm);
    }
}