<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User-facing notification endpoints (Phase 13.1).
 *
 * Role-agnostic — every authenticated user sees only their own rows.
 * `auth:api` alone, no `role:*` gate.
 */
class NotificationController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $query = Notification::query()
            ->where('user_id', $userId)
            ->whereNull('dismissed_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('created_at');

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        // Support both offset/limit (Flutter repo) and page/per_page.
        $perPage = 0;
        $page = 1;

        if ($request->filled('per_page')) {
            $perPage = min((int) $request->query('per_page'), 100);
            $page = (int) $request->query('page', 1);
        } elseif ($request->filled('limit')) {
            $perPage = min((int) $request->query('limit'), 100);
            $offset = (int) $request->query('offset', 0);
            $page = intdiv($offset, max(1, $perPage)) + 1;
        }

        if ($perPage <= 0) {
            $paginated = $query->paginate($query->count() ?: 20);
        } else {
            $paginated = $query->paginate($perPage, ['*'], 'page', $page);
        }

        $items = [];
        foreach ($paginated->items() as $notification) {
            $items[] = (new NotificationResource($notification))->toArray($request);
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

    public function markRead(Request $request, int $notification): JsonResponse
    {
        $model = $this->resolveOwned($request, $notification);
        if ($model instanceof JsonResponse) return $model;

        $model->markAsRead();

        return $this->success(
            data: new NotificationResource($model),
            message: 'Notification marked as read.',
        );
    }

    public function dismiss(Request $request, int $notification): JsonResponse
    {
        $model = $this->resolveOwned($request, $notification);
        if ($model instanceof JsonResponse) return $model;

        $model->dismiss();

        return $this->success(
            data: new NotificationResource($model),
            message: 'Notification dismissed.',
        );
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $count = Notification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->update(['read_at' => now()]);

        return $this->success(
            data: ['marked_count' => $count],
            message: 'All notifications marked as read.',
        );
    }

    private function resolveOwned(Request $request, int $notificationId): Notification|JsonResponse
    {
        $model = Notification::where('id', $notificationId)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($model === null) {
            return $this->error('Notification not found.', null, 404);
        }
        return $model;
    }
}