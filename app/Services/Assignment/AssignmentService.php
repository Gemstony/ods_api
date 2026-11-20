<?php

namespace App\Services\Assignment;

use App\Models\Branch;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Assigns `batched` Receive and Send deliveries to waiting riders.
 *
 * Queue model:
 *   - Riders queue at their branch by `available_since` (FIFO)
 *   - Pair up to two same-recipient, same-distributor, or nearby deliveries
 *   - Assign each delivery group to the front of the branch queue
 *   - Assign → is_available = false, delivery → assigned, expires_at set
 *   - On timeout (Phase 10.3) or completion, rider rejoins back of queue
 *
 * Receive deliveries are assigned to riders at their pickup branch. Send
 * deliveries with a selected pickup branch use that branch; location pickups
 * go to the nearest active branch within the configured pickup radius.
 * Regional stays manual.
 *
 * Concurrency: the riders query is wrapped in `lockForUpdate()` inside
 * the same transaction that writes both the delivery and the rider's
 * `is_available` flag. Two simultaneous runs can't double-assign.
 *
 * If there are more deliveries than waiting riders, excess deliveries
 * stay `batched` and retry on the next run (10.3 schedules this).
 */
class AssignmentService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  int|null  $branchId  Limit to one branch (Manager button).
     *                              Null = global (scheduled job).
     */
    public function assignBatchedDeliveries(?int $branchId = null): AssignmentResult
    {
        $timeoutMinutes = (int) Setting::get(
            'location.assignment_timeout_minutes',
            5,
        );

        $query = Delivery::query()
            ->where('status', Delivery::STATUS_BATCHED)
            ->whereNull('rider_id')
            ->whereIn('service_type', [Delivery::SERVICE_RECEIVE, Delivery::SERVICE_SEND]);

        if ($branchId !== null) {
            $query->where(function ($query) use ($branchId): void {
                $query->where(function ($query) use ($branchId): void {
                    $query->where('service_type', Delivery::SERVICE_RECEIVE)
                        ->where('pickup_branch_id', $branchId);
                })->orWhere(function ($query) use ($branchId): void {
                    $query->where('service_type', Delivery::SERVICE_SEND)
                        ->where(function ($query) use ($branchId): void {
                            $query->whereNull('pickup_branch_id')
                                ->orWhere('pickup_branch_id', $branchId);
                        });
                });
            });
        }

        $deliveries = $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($deliveries->isEmpty()) {
            return AssignmentResult::success(
                assignmentsMade: 0,
                deliveriesUnassigned: 0,
                deliveriesSkipped: 0,
                assignments: [],
                runAt: now(),
            );
        }

        $branches = Branch::query()
            ->where('status', Branch::STATUS_ACTIVE)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($branchId !== null, fn ($query) => $query->whereKey($branchId))
            ->get();
        $pickupRadiusKm = (float) Setting::get('location.pickup_radius_km', 5);
        $byBranch = collect();
        $made = [];
        $unassigned = 0;
        $skipped = 0;

        foreach ($deliveries as $delivery) {
            $deliveryBranchId = $delivery->pickup_branch_id !== null
                ? ($branches->contains('id', (int) $delivery->pickup_branch_id)
                    ? (int) $delivery->pickup_branch_id
                    : null)
                : ($delivery->service_type === Delivery::SERVICE_SEND
                    ? $this->nearestPickupBranchId($delivery, $branches, $pickupRadiusKm)
                    : null);

            if ($deliveryBranchId === null) {
                $skipped++;

                continue;
            }

            $branchDeliveries = $byBranch->get($deliveryBranchId, collect());
            $branchDeliveries->push($delivery);
            $byBranch->put($deliveryBranchId, $branchDeliveries);
        }

        foreach ($byBranch as $bId => $branchDeliveries) {

            [$batchMade, $batchUnassigned] = DB::transaction(function () use ($bId, $branchDeliveries, $timeoutMinutes) {
                /** @var Collection<int,User> $riders */
                $riders = User::query()
                    ->where('branch_id', $bId)
                    ->whereHas('role', fn ($q) => $q->where('name', Role::RIDER))
                    ->where('status', User::STATUS_ACTIVE)
                    ->where('is_available', true)
                    ->orderBy('available_since')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $riderIndex = 0;
                $made = [];
                $unassigned = 0;
                $deliveryGroups = $this->groupNearbyDeliveries($branchDeliveries);

                foreach ($deliveryGroups as $deliveryGroup) {
                    if ($riderIndex >= $riders->count()) {
                        $unassigned += count($deliveryGroup);

                        continue;
                    }

                    $rider = $riders[$riderIndex];
                    $assignmentGroupId = count($deliveryGroup) > 1
                        ? 'AG-'.strtoupper(bin2hex(random_bytes(6)))
                        : null;
                    $assignedAt = now();
                    $expiresAt = $assignedAt->copy()->addMinutes($timeoutMinutes);

                    foreach ($deliveryGroup as $delivery) {
                        $delivery->update([
                            'rider_id' => $rider->id,
                            'status' => Delivery::STATUS_ASSIGNED,
                            'assigned_at' => $assignedAt,
                            'assignment_expires_at' => $expiresAt,
                            'assignment_group_id' => $assignmentGroupId,
                        ]);

                        try {
                            $this->notifications->deliveryAssigned($delivery->fresh());
                        } catch (\Throwable $e) {
                            Log::error(
                                'Notification write after assignment failed',
                                ['delivery_id' => $delivery->id, 'error' => $e->getMessage()],
                            );
                        }

                        $made[] = [
                            'delivery_id' => $delivery->id,
                            'rider_id' => $rider->id,
                            'rider_name' => $rider->name,
                            'expires_at' => $expiresAt->toIso8601String(),
                            'assignment_group_id' => $assignmentGroupId,
                        ];
                    }

                    $rider->markBusy();
                    $riderIndex++;
                }

                return [$made, $unassigned];
            });

            foreach ($batchMade as $entry) {
                $made[] = $entry;
            }
            $unassigned += $batchUnassigned;
        }

        if (! empty($made)) {
            $this->auditLogger->log(
                auth()->user(),
                action: 'assignment.run_completed',
                description: sprintf(
                    'Assigned %d deliveries to riders.',
                    count($made),
                ),
                subject: null,
                changes: [
                    'assignments_made' => count($made),
                    'deliveries_unassigned' => $unassigned,
                    'deliveries_skipped' => $skipped,
                    'branch_scope' => $branchId,
                    'assignments' => $made,
                ],
            );
        }

        return AssignmentResult::success(
            assignmentsMade: count($made),
            deliveriesUnassigned: $unassigned,
            deliveriesSkipped: $skipped,
            assignments: $made,
            runAt: now(),
        );
    }

    /**
     * Find the nearest active branch that can dispatch a Send pickup.
     *
     * @param  Collection<int, Branch>  $branches
     */
    private function nearestPickupBranchId(Delivery $delivery, Collection $branches, float $pickupRadiusKm): ?int
    {
        if ($delivery->pickup_latitude === null || $delivery->pickup_longitude === null) {
            return null;
        }

        $nearestBranchId = null;
        $nearestDistance = $pickupRadiusKm;

        foreach ($branches as $branch) {
            $distance = $this->distanceInKilometers(
                (float) $delivery->pickup_latitude,
                (float) $delivery->pickup_longitude,
                (float) $branch->latitude,
                (float) $branch->longitude,
            );

            if ($distance <= $nearestDistance) {
                $nearestBranchId = $branch->id;
                $nearestDistance = $distance;
            }
        }

        return $nearestBranchId;
    }

    /**
     * Pair deliveries for the same recipient or within 2 km of each other.
     * A rider gets at most two deliveries in one assignment group.
     *
     * @param  Collection<int, Delivery>  $deliveries
     * @return array<int, array<int, Delivery>>
     */
    private function groupNearbyDeliveries(Collection $deliveries): array
    {
        $remaining = $deliveries->values()->all();
        $groups = [];

        while ($remaining !== []) {
            $delivery = array_shift($remaining);
            $group = [$delivery];

            foreach ($remaining as $index => $candidate) {
                if ($this->canShareAssignment($delivery, $candidate)) {
                    $group[] = $candidate;
                    array_splice($remaining, $index, 1);
                    break;
                }
            }

            $groups[] = $group;
        }

        return $groups;
    }

    private function canShareAssignment(Delivery $first, Delivery $second): bool
    {
        if ($first->service_type !== $second->service_type) {
            return false;
        }

        if ($first->distributor_id === $second->distributor_id) {
            return true;
        }

        $firstRecipientPhone = preg_replace('/\\D+/', '', (string) $first->recipient_phone);
        $secondRecipientPhone = preg_replace('/\\D+/', '', (string) $second->recipient_phone);

        if ($firstRecipientPhone !== '' && $firstRecipientPhone === $secondRecipientPhone) {
            return true;
        }

        if (
            $first->destination_latitude === null || $first->destination_longitude === null ||
            $second->destination_latitude === null || $second->destination_longitude === null
        ) {
            return false;
        }

        return $this->distanceInKilometers(
            (float) $first->destination_latitude,
            (float) $first->destination_longitude,
            (float) $second->destination_latitude,
            (float) $second->destination_longitude,
        ) <= 2;
    }

    private function distanceInKilometers(float $latitudeOne, float $longitudeOne, float $latitudeTwo, float $longitudeTwo): float
    {
        $latitudeDifference = deg2rad($latitudeTwo - $latitudeOne);
        $longitudeDifference = deg2rad($longitudeTwo - $longitudeOne);
        $latitudeOne = deg2rad($latitudeOne);
        $latitudeTwo = deg2rad($latitudeTwo);

        $a = sin($latitudeDifference / 2) ** 2
            + cos($latitudeOne) * cos($latitudeTwo) * sin($longitudeDifference / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($a)));
    }

    /**
     * Release Receive or Send deliveries whose rider did not accept them in time.
     *
     * The delivery remains in its existing batch so the next assignment run
     * can retry it without waiting for another batching window.
     */
    public function releaseExpiredAssignments(?int $branchId = null): int
    {
        $released = 0;

        DB::transaction(function () use ($branchId, &$released): void {
            $query = Delivery::query()
                ->where('status', Delivery::STATUS_ASSIGNED)
                ->whereNotNull('rider_id')
                ->whereNotNull('assignment_expires_at')
                ->where('assignment_expires_at', '<=', now())
                ->whereIn('service_type', [Delivery::SERVICE_RECEIVE, Delivery::SERVICE_SEND])
                ->lockForUpdate();

            if ($branchId !== null) {
                $query->where(function ($query) use ($branchId): void {
                    $query->where('pickup_branch_id', $branchId)
                        ->orWhere(function ($query) use ($branchId): void {
                            $query->where('service_type', Delivery::SERVICE_SEND)
                                ->whereHas('rider', fn ($query) => $query->where('branch_id', $branchId));
                        });
                });
            }

            $deliveries = $query->get();

            foreach ($deliveries as $delivery) {
                $rider = User::query()->lockForUpdate()->find($delivery->rider_id);

                $delivery->update([
                    'rider_id' => null,
                    'status' => Delivery::STATUS_BATCHED,
                    'assigned_at' => null,
                    'assignment_expires_at' => null,
                ]);

                if ($rider !== null && $rider->currentDelivery() === null) {
                    $rider->markAvailable();
                }

                $released++;
            }
        });

        return $released;
    }
}
