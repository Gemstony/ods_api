<?php

namespace App\Services\Assignment;

use App\Models\Delivery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assigns `batched` receive deliveries to waiting riders (Phase 10.2).
 *
 * Queue model:
 *   - Riders queue at their branch by `available_since` (FIFO)
 *   - For each batched delivery, pop the front of the branch queue
 *   - Assign → is_available = false, delivery → assigned, expires_at set
 *   - On timeout (Phase 10.3) or completion, rider rejoins back of queue
 *
 * Only receive deliveries are auto-assigned in 10.2. Regional stays
 * manual (dispatcher self-assign, 9.1). Send is deferred — pickup
 * logistics need more design.
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
    public function __construct(private readonly AuditLogger $auditLogger) {}

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
            ->where('service_type', Delivery::SERVICE_RECEIVE);

        if ($branchId !== null) {
            $query->where('pickup_branch_id', $branchId);
        }

        $deliveries = $query->get();

        if ($deliveries->isEmpty()) {
            return AssignmentResult::success(
                assignmentsMade: 0,
                deliveriesUnassigned: 0,
                deliveriesSkipped: 0,
                assignments: [],
                runAt: now(),
            );
        }

        $byBranch = $deliveries->groupBy('pickup_branch_id');
        $made = [];
        $unassigned = 0;
        $skipped = 0;

        foreach ($byBranch as $bId => $branchDeliveries) {
            if ($bId === null) {
                // Defensive: receive deliveries without a branch can't be
                // queued. Skip and leave status alone — batch job (10.1)
                // would have skipped them too.
                $skipped += $branchDeliveries->count();

                continue;
            }

            [$batchMade, $batchUnassigned] = DB::transaction(function () use (
                $bId,
                $branchDeliveries,
                $timeoutMinutes,
            ) {
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

                foreach ($branchDeliveries as $delivery) {
                    /** @var Delivery $delivery */
                    if ($riderIndex >= $riders->count()) {
                        $unassigned++;

                        continue;
                    }

                    $rider = $riders[$riderIndex];

                    $delivery->update([
                        'rider_id' => $rider->id,
                        'status' => Delivery::STATUS_ASSIGNED,
                        'assigned_at' => now(),
                        'assignment_expires_at' => now()->addMinutes($timeoutMinutes),
                    ]);

                    $rider->markBusy();

                    $made[] = [
                        'delivery_id' => $delivery->id,
                        'rider_id' => $rider->id,
                        'rider_name' => $rider->name,
                        'expires_at' => $delivery->assignment_expires_at->toIso8601String(),
                    ];

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
}
