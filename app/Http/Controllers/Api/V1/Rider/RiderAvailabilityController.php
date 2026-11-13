<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rider queue availability (Phase 10.2).
 *
 * Endpoints:
 *   PATCH /rider/availability       toggle is_available
 *   GET   /rider/queue-status       current queue state + position
 *
 * Riders use these to opt in/out of the assignment queue (breaks,
 * lunch, end of shift) and to see how many riders are ahead of them.
 */
class RiderAvailabilityController extends Controller
{
    use ApiResponse;

    // ─── toggle ───────────────────────────────────────────────
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'available' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $available = (bool) $request->input('available');

        if ($user->branch_id === null) {
            return $this->error(
                'Your account is not assigned to a branch.',
                null,
                403,
            );
        }

        // Idempotent: going available while already available does NOT
        // reset available_since (rider keeps their place in line).
        if ($available && ! $user->is_available) {
            $user->markAvailable();
        } elseif (! $available && $user->is_available) {
            // Also prevent going unavailable while carrying a delivery.
            if ($user->currentDelivery() !== null) {
                return $this->error(
                    'Finish your current delivery before going unavailable.',
                    null,
                    409,
                );
            }
            $user->markBusy();
        }

        return $this->success(
            data: $this->queueStatusPayload($user->fresh()),
            message: 'Availability updated.',
        );
    }

    // ─── status ───────────────────────────────────────────────
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->branch_id === null) {
            return $this->error(
                'Your account is not assigned to a branch.',
                null,
                403,
            );
        }

        return $this->success(
            data: $this->queueStatusPayload($user),
            message: 'OK',
        );
    }

    // ─── Helpers ──────────────────────────────────────────────
    private function queueStatusPayload(User $user): array
    {
        $currentDelivery = $user->currentDelivery();

        $queuePosition = null;
        $queueDepth = null;

        if ($user->branch_id !== null) {
            // Depth = total available riders at this branch.
            $queueDepth = User::query()
                ->where('branch_id', $user->branch_id)
                ->whereHas('role', fn ($q) => $q->where('name', Role::RIDER))
                ->where('status', User::STATUS_ACTIVE)
                ->where('is_available', true)
                ->count();

            // Position = riders ahead of this one (strictly earlier
            // available_since). If not available, they're outside the
            // queue so position is null.
            if ($user->is_available && $user->available_since !== null) {
                $ahead = User::query()
                    ->where('branch_id', $user->branch_id)
                    ->whereHas('role', fn ($q) => $q->where('name', Role::RIDER))
                    ->where('status', User::STATUS_ACTIVE)
                    ->where('is_available', true)
                    ->where(function ($q) use ($user) {
                        $q->where('available_since', '<', $user->available_since)
                            ->orWhere(function ($inner) use ($user) {
                                $inner->where('available_since', $user->available_since)
                                    ->where('id', '<', $user->id);
                            });
                    })
                    ->count();

                $queuePosition = $ahead + 1; // 1-indexed
            }
        }

        return [
            'is_available' => (bool) $user->is_available,
            'available_since' => $user->available_since?->toIso8601String(),
            'branch_id' => $user->branch_id,
            'queue_position' => $queuePosition,
            'queue_depth' => $queueDepth,
            'current_delivery' => $currentDelivery === null ? null : [
                'id' => $currentDelivery->id,
                'tracking_id' => $currentDelivery->tracking_id,
                'status' => $currentDelivery->status,
            ],
        ];
    }
}
