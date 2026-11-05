<?php

namespace App\Http\Controllers\Api\V1\Receiver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Receiver\DispatchRegionalRequest;
use App\Http\Resources\DeliveryResource;
use App\Http\Resources\DispatcherTicketResource;
use App\Models\Delivery;
use App\Models\DispatcherTicket;
use App\Services\Audit\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Regional dispatch endpoints for receivers with `dispatcher_enabled`.
 *
 * Status sequence (Decision B):
 *   batched --assign--> assigned --dispatch--> dispatched
 *
 *   `assigned`    = dispatcher_id set; responsibility accepted
 *   `dispatched`  = physically handed to provider; ticket issued
 *
 * Both actions gate on `dispatcher_enabled` and branch scope.
 *
 * Endpoints:
 *   GET  /receiver/regional                 → index
 *   GET  /receiver/regional/{delivery}      → show (includes ticket if any)
 *   POST /receiver/regional/{delivery}/assign    → batched → assigned
 *   POST /receiver/regional/{delivery}/dispatch  → assigned → dispatched
 *   GET  /receiver/regional/{delivery}/ticket    → view ticket
 */
class RegionalDispatcherController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    // ─── index ────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $gate = $this->gate($request);
        if ($gate !== null) return $gate;

        $query = Delivery::query()
            ->where('pickup_branch_id', $request->user()->branch_id)
            ->where('service_type', Delivery::SERVICE_REGIONAL)
            ->with(['items', 'dispatcherTicket'])
            ->orderByDesc('created_at');

        $defaultStatuses = [
            Delivery::STATUS_BATCHED,
            Delivery::STATUS_ASSIGNED,
        ];

        $requested = $request->filled('status')
            ? array_filter(array_map('trim', explode(',', (string) $request->query('status'))))
            : $defaultStatuses;

        $valid = [
            Delivery::STATUS_BATCHED,
            Delivery::STATUS_ASSIGNED,
            Delivery::STATUS_DISPATCHED,
            Delivery::STATUS_DELIVERED,
            Delivery::STATUS_CANCELLED,
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
        $model = $this->resolveRegional($request, $delivery);
        if ($model instanceof JsonResponse) return $model;

        return $this->success(new DeliveryResource($model), 'OK');
    }

    // ─── assign ───────────────────────────────────────────────
    public function assign(Request $request, int $delivery): JsonResponse
    {
        $gate = $this->gate($request);
        if ($gate !== null) return $gate;

        $model = $this->resolveRegional($request, $delivery);
        if ($model instanceof JsonResponse) return $model;

        if ($model->status !== Delivery::STATUS_BATCHED) {
            return $this->error(
                "Cannot assign: current status is '{$model->status}', expected 'batched'.",
                [
                    'current_status' => $model->status,
                    'expected_status' => Delivery::STATUS_BATCHED,
                ],
                422,
            );
        }

        $oldStatus = $model->status;
        $model->status = Delivery::STATUS_ASSIGNED;
        $model->dispatcher_id = $request->user()->id;
        $model->save();

        $this->auditLogger->logCurrentUser(
            action: 'delivery.dispatcher_assigned',
            description: "Assigned self as dispatcher for regional delivery {$model->tracking_id}.",
            subject: $model,
            changes: [
                'status' => ['from' => $oldStatus, 'to' => Delivery::STATUS_ASSIGNED],
                'dispatcher_id' => $request->user()->id,
            ],
        );

        $model->load(['items', 'dispatcherTicket']);

        return $this->success(new DeliveryResource($model), 'Dispatcher assigned.');
    }

    // ─── dispatch ─────────────────────────────────────────────
    public function dispatch(
        DispatchRegionalRequest $request,
        int $delivery,
    ): JsonResponse {
        $gate = $this->gate($request);
        if ($gate !== null) return $gate;

        $model = $this->resolveRegional($request, $delivery);
        if ($model instanceof JsonResponse) return $model;

        if ($model->status !== Delivery::STATUS_ASSIGNED) {
            return $this->error(
                "Cannot dispatch: current status is '{$model->status}', expected 'assigned'.",
                [
                    'current_status' => $model->status,
                    'expected_status' => Delivery::STATUS_ASSIGNED,
                ],
                422,
            );
        }

        if ($model->dispatcherTicket !== null) {
            return $this->error(
                'This delivery already has a dispatch ticket.',
                null,
                422,
            );
        }

        [$ticket, $oldStatus] = DB::transaction(function () use ($request, $model) {
            $ticket = DispatcherTicket::create([
                'delivery_id' => $model->id,
                'dispatcher_id' => $request->user()->id,
                'provider_name' => $request->input('provider_name'),
                'provider_type' => $request->input('provider_type'),
                'ticket_number' => $request->input('ticket_number'),
                'route_from' => $request->input('route_from'),
                'route_to' => $request->input('route_to'),
                'transport_fare' => $request->input('transport_fare')
                    ?? $model->bus_cargo_fare
                    ?? 0,
                'currency' => $model->currency ?? 'TZS',
                'issued_at' => now(),
                'ticket_image_path' => $request->input('ticket_image_path'),
                'notes' => $request->input('notes'),
            ]);

            $old = $model->status;
            $model->status = Delivery::STATUS_DISPATCHED;
            $model->dispatched_at = now();
            $model->dispatcher_id = $request->user()->id;
            $model->save();

            return [$ticket, $old];
        });

        $this->auditLogger->logCurrentUser(
            action: 'delivery.dispatched',
            description: "Dispatched regional delivery {$model->tracking_id} via "
                . "{$ticket->provider_name} ({$ticket->ticket_number}).",
            subject: $model,
            changes: [
                'status' => ['from' => $oldStatus, 'to' => Delivery::STATUS_DISPATCHED],
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'transport_fare' => (float) $ticket->transport_fare,
            ],
        );

        $model->load(['items', 'dispatcherTicket']);

        return $this->success(new DeliveryResource($model), 'Regional delivery dispatched.');
    }

    // ─── ticket ───────────────────────────────────────────────
    public function ticket(Request $request, int $delivery): JsonResponse
    {
        $model = $this->resolveRegional($request, $delivery);
        if ($model instanceof JsonResponse) return $model;

        if ($model->dispatcherTicket === null) {
            return $this->error('No ticket has been issued for this delivery.', null, 404);
        }

        $model->dispatcherTicket->load('dispatcher:id,name');

        return $this->success(
            new DispatcherTicketResource($model->dispatcherTicket),
            'OK',
        );
    }

    // ─── Helpers ──────────────────────────────────────────────
    /**
     * Returns null when the request may proceed, or a 403 JsonResponse.
     */
    private function gate(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user->branch_id === null) {
            return $this->error(
                'Your account is not assigned to a branch.',
                null,
                403,
            );
        }
        if (! $user->dispatcher_enabled) {
            return $this->error(
                'Regional dispatch requires dispatcher privileges. Contact your manager.',
                null,
                403,
            );
        }
        return null;
    }

    private function resolveRegional(Request $request, int $id): Delivery|JsonResponse
    {
        $user = $request->user();
        if ($user->branch_id === null || ! $user->dispatcher_enabled) {
            return $this->error(
                'Regional dispatch requires dispatcher privileges.',
                null,
                403,
            );
        }

        $model = Delivery::with(['items', 'dispatcherTicket.dispatcher:id,name'])
            ->where('pickup_branch_id', $user->branch_id)
            ->where('service_type', Delivery::SERVICE_REGIONAL)
            ->where('id', $id)
            ->first();

        if (! $model) {
            return $this->error('Regional delivery not found.', null, 404);
        }
        return $model;
    }
}