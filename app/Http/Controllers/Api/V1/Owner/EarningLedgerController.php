<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Http\Resources\EarningResource;
use App\Models\Earning;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner-facing earning ledger (Phase 11.4).
 *
 *   GET /owner/earnings               → paginated list, filterable
 *   GET /owner/earnings/{earning}     → single earning
 */
class EarningLedgerController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Earning::with(['user:id,name,email', 'delivery:id,tracking_id,status'])
            ->orderByDesc('earned_at')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $raw = (string) $request->query('status');
            $requested = array_filter(array_map('trim', explode(',', $raw)));
            $valid = [
                Earning::STATUS_PENDING,
                Earning::STATUS_APPROVED,
                Earning::STATUS_PAID,
                Earning::STATUS_CANCELLED,
            ];
            $filtered = array_values(array_intersect($requested, $valid));
            if (! empty($filtered)) {
                $query->whereIn('status', $filtered);
            }
        }

        if ($request->filled('type')) {
            $raw = (string) $request->query('type');
            $requested = array_filter(array_map('trim', explode(',', $raw)));
            $valid = [
                Earning::TYPE_RIDER_BASELINE,
                Earning::TYPE_RIDER_BONUS,
                Earning::TYPE_DISPATCHER_FLAT,
                Earning::TYPE_DISPATCHER_SHIFT,
                Earning::TYPE_DISPATCHER_PERCENT,
                Earning::TYPE_ADJUSTMENT,
            ];
            $filtered = array_values(array_intersect($requested, $valid));
            if (! empty($filtered)) {
                $query->whereIn('type', $filtered);
            }
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        if ($request->filled('delivery_id')) {
            $query->where('delivery_id', (int) $request->query('delivery_id'));
        }

        if ($request->filled('from')) {
            $query->where('earned_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('earned_at', '<=', $request->query('to') . ' 23:59:59');
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;
        $paginated = $query->paginate($perPage);

        $mapped = [];
        foreach ($paginated->items() as $earning) {
            $mapped[] = (new EarningResource($earning))->toArray($request);
        }

        return $this->success(
            data: [
                'data' => $mapped,
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
            message: 'OK',
        );
    }

    public function show(Request $request, int $earning): JsonResponse
    {
        $model = Earning::with(['user:id,name,email', 'delivery:id,tracking_id,status'])
            ->find($earning);

        if ($model === null) {
            return $this->error('Earning not found.', null, 404);
        }

        return $this->success(
            data: new EarningResource($model),
            message: 'OK',
        );
    }
}