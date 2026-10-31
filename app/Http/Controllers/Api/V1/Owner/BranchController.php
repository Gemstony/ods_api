<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Owner\StoreBranchRequest;
use App\Http\Requests\Api\V1\Owner\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner-facing branch CRUD.
 *
 * Auth: all routes are wrapped in `role:owner` middleware (see routes).
 * This controller trusts that the caller is an authenticated, active Owner.
 *
 * Endpoints:
 *   GET  /owner/branches               → index (paginated)
 *   POST /owner/branches               → store
 *   GET  /owner/branches/{branch}      → show
 *   PUT  /owner/branches/{branch}      → update
 *
 * Deactivation is done by updating `status` to `inactive` (no delete
 * endpoint — soft-deleting a branch would orphan its delivery history).
 */
class BranchController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/owner/branches
     *
     * Query params:
     *   status    active|inactive (optional — filters results)
     *   per_page  int 1–100 (optional — defaults to 20)
     *
     * Ordered by name ascending for stable UI display.
     *
     * Response shape (matches Flutter BranchRepository._extractList):
     *   {
     *     "success": true,
     *     "message": "OK",
     *     "data": {
     *       "data": [ {branch}, {branch}, ... ],
     *       "total": 3,
     *       "per_page": 20,
     *       "current_page": 1,
     *       "last_page": 1
     *     }
     *   }
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        $query = Branch::query()->orderBy('name');

        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
        }

        $paginated = $query->paginate($perPage);

        // Build each branch as a plain array. Using ->toArray($request)
        // (rather than ->resolve() or ResourceCollection) keeps the shape
        // deterministic — response()->json() serializes plain arrays
        // exactly as expected.
        $items = [];
        foreach ($paginated->items() as $branch) {
            $items[] = (new BranchResource($branch))->toArray($request);
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

    /**
     * POST /api/v1/owner/branches
     */
    public function store(StoreBranchRequest $request): JsonResponse
    {
        $branch = Branch::create([
            'name' => $request->input('name'),
            'code' => $request->input('code'),
            'address' => $request->input('address'),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'operating_hours' => $request->input('operating_hours'),
            'status' => $request->input('status', Branch::STATUS_ACTIVE),
            'created_by' => $request->user()->id,
        ]);

        return $this->success(
            data: new BranchResource($branch),
            message: 'Branch created.',
            status: 201,
        );
    }

    /**
     * GET /api/v1/owner/branches/{branch}
     */
    public function show(Branch $branch): JsonResponse
    {
        return $this->success(new BranchResource($branch), 'OK');
    }

    /**
     * PUT /api/v1/owner/branches/{branch}
     */
    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $branch->update([
            'name' => $request->input('name'),
            'code' => $request->input('code'),
            'address' => $request->input('address'),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'operating_hours' => $request->input('operating_hours'),
            'status' => $request->input('status'),
        ]);

        return $this->success(
            data: new BranchResource($branch->fresh()),
            message: 'Branch updated.',
        );
    }
}