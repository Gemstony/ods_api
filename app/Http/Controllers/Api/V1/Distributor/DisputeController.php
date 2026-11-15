<?php

namespace App\Http\Controllers\Api\V1\Distributor;

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
 * Distributor disputes (Phase 13.4).
 *
 *   POST /distributor/disputes              → open
 *   GET  /distributor/disputes              → list own
 *   GET  /distributor/disputes/{dispute}    → show own
 */
class DisputeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DisputeService $disputes) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $query = Dispute::with(['delivery:id,tracking_id,status,service_type'])
            ->where('opened_by', $userId)
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
        $model = Dispute::with([
            'delivery:id,tracking_id,status,service_type',
            'openedBy:id,name',
            'againstUser:id,name',
            'resolvedBy:id,name',
        ])
            ->where('id', $dispute)
            ->where('opened_by', $request->user()->id)
            ->first();

        if ($model === null) {
            return $this->error('Dispute not found.', null, 404);
        }

        return $this->success(data: new DisputeResource($model), message: 'OK');
    }
}