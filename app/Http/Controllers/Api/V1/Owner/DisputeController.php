<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Owner\ResolveDisputeRequest;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Services\Disputes\DisputeException;
use App\Services\Disputes\DisputeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner dispute management (Phase 13.4).
 *
 *   GET  /owner/disputes                       → list all
 *   GET  /owner/disputes/{dispute}             → show one
 *   POST /owner/disputes/{dispute}/review      → open → in_review
 *   POST /owner/disputes/{dispute}/resolve     → * → resolved (with note)
 *   POST /owner/disputes/{dispute}/close       → * → closed (with optional reason)
 *   POST /owner/disputes/{dispute}/escalate    → * → escalated
 */
class DisputeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DisputeService $disputes) {}

    public function index(Request $request): JsonResponse
    {
        $query = Dispute::with(['delivery:id,tracking_id,status,service_type'])
            ->orderByDesc('priority')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->filled('category')) {
            $query->where('category', (string) $request->query('category'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', (string) $request->query('priority'));
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

    public function show(Request $request, int $dispute): JsonResponse
    {
        $model = Dispute::with([
            'delivery:id,tracking_id,status,service_type',
            'openedBy:id,name,email',
            'againstUser:id,name,email',
            'resolvedBy:id,name',
        ])->find($dispute);

        if ($model === null) {
            return $this->error('Dispute not found.', null, 404);
        }

        return $this->success(data: new DisputeResource($model), message: 'OK');
    }

    public function startReview(Request $request, int $dispute): JsonResponse
    {
        return $this->runAction(
            request: $request,
            disputeId: $dispute,
            action: fn (Dispute $d) => $this->disputes->startReview($request->user(), $d),
            successMessage: 'Review started.',
        );
    }

    public function resolve(ResolveDisputeRequest $request, int $dispute): JsonResponse
    {
        return $this->runAction(
            request: $request,
            disputeId: $dispute,
            action: fn (Dispute $d) => $this->disputes->resolve(
                $request->user(),
                $d,
                (string) $request->input('resolution'),
            ),
            successMessage: 'Dispute resolved.',
        );
    }

    public function close(Request $request, int $dispute): JsonResponse
    {
        return $this->runAction(
            request: $request,
            disputeId: $dispute,
            action: fn (Dispute $d) => $this->disputes->close(
                $request->user(),
                $d,
                $request->input('reason') !== null
                    ? (string) $request->input('reason')
                    : null,
            ),
            successMessage: 'Dispute closed.',
        );
    }

    public function escalate(Request $request, int $dispute): JsonResponse
    {
        return $this->runAction(
            request: $request,
            disputeId: $dispute,
            action: fn (Dispute $d) => $this->disputes->escalate($request->user(), $d),
            successMessage: 'Dispute escalated.',
        );
    }

    // ═════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════

    private function runAction(
        Request $request,
        int $disputeId,
        callable $action,
        string $successMessage,
    ): JsonResponse {
        $dispute = Dispute::find($disputeId);
        if ($dispute === null) {
            return $this->error('Dispute not found.', null, 404);
        }

        try {
            $updated = $action($dispute);
        } catch (DisputeException $e) {
            return $this->error($e->getMessage(), $e->context, $e->httpStatus);
        }

        $updated->load([
            'delivery:id,tracking_id,status,service_type',
            'openedBy:id,name',
            'resolvedBy:id,name',
        ]);

        return $this->success(
            data: new DisputeResource($updated),
            message: $successMessage,
        );
    }
}