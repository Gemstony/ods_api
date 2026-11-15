<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Distributor\OpenDisputeRequest;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Services\Disputes\DisputeException;
use App\Services\Disputes\DisputeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rider disputes (Phase 13.4).
 *
 *   POST /rider/disputes              → open (about own assigned delivery)
 *   GET  /rider/disputes              → list (own + assigned)
 *   GET  /rider/disputes/{dispute}    → show (own + assigned)
 *
 * Riders CANNOT resolve, close, or escalate.
 */
class DisputeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DisputeService $disputes) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $query = Dispute::with(['delivery:id,tracking_id,status,service_type'])
            ->where(function ($q) use ($userId) {
                $q->where('opened_by', $userId)
                  ->orWhereHas('delivery', fn ($d) => $d->where('rider_id', $userId));
            })
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $paginated = $query->paginate(20);
        $items = [];
        foreach ($paginated->items() as $dispute) {
            $items[] = (new DisputeResource($dispute))->toArray($request);
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

    public function store(OpenDisputeRequest $request): JsonResponse
    {
        try {
            $dispute = $this->disputes->open(
                opener: $request->user(),
                deliveryId: $request->integer('delivery_id'),
                category: (string) $request->input('category'),
                subject: (string) $request->input('subject'),
                description: (string) $request->input('description'),
                priority: (string) ($request->input('priority') ?? Dispute::PRIORITY_NORMAL),
                againstUserId: $request->filled('against_user_id')
                    ? $request->integer('against_user_id')
                    : null,
                attachments: (array) ($request->input('attachments') ?? []),
            );
        } catch (DisputeException $e) {
            return $this->error($e->getMessage(), $e->context, $e->httpStatus);
        }

        $dispute->load(['delivery:id,tracking_id,status,service_type', 'openedBy:id,name']);

        return $this->success(
            data: new DisputeResource($dispute),
            message: 'Dispute opened.',
            status: 201,
        );
    }

    public function show(Request $request, int $dispute): JsonResponse
    {
        $userId = $request->user()->id;

        $model = Dispute::with([
            'delivery:id,tracking_id,status,service_type',
            'openedBy:id,name',
            'againstUser:id,name',
            'resolvedBy:id,name',
        ])
            ->where('id', $dispute)
            ->where(function ($q) use ($userId) {
                $q->where('opened_by', $userId)
                  ->orWhereHas('delivery', fn ($d) => $d->where('rider_id', $userId));
            })
            ->first();

        if ($model === null) {
            return $this->error('Dispute not found.', null, 404);
        }

        return $this->success(data: new DisputeResource($model), message: 'OK');
    }
}