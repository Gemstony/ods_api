<?php

namespace App\Services\Batching;

use App\Models\Delivery;
use App\Models\Setting;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Groups `paid` deliveries into batches (Phase 10.1).
 *
 * Batching model — **fixed window**:
 *   - Each run collects all currently `paid` + un-batched deliveries
 *   - Groups by service_type + pickup_branch (receive/regional) or
 *     destination GPS bucket (send)
 *   - Transitions each group to `batched` with a batch_id and a window
 *     of `batching.window_minutes` from now
 *   - Lone deliveries still batch (batch-of-1) — nobody gets stranded
 *
 * Downstream (Phase 10.2) the assignment engine iterates batches and
 * assigns riders/dispatchers. Phase 10.3 schedules the job that calls
 * this service automatically.
 *
 * "Same location" definition per service:
 *   Receive / Regional → same pickup_branch_id
 *   Send               → same rounded destination GPS (~11 km bucket)
 *
 * Sends without GPS coordinates cannot be grouped and are counted as
 * `deliveriesSkipped` in the result. Receives/Regional without a branch
 * are likewise skipped (data-integrity edge case — should not occur in
 * practice but is defensive).
 *
 * Separate batches per service_type: a Receive and a Regional delivery
 * from the same branch form two batches, because their downstream
 * workflows diverge (rider pickup vs bus dispatch).
 *
 * **Audit actor:** the audit write uses `AuditLogger::log()` with the
 * currently authenticated user (if any). When run from the Manager API,
 * the actor is the manager. When run from the scheduled job, CLI, or a
 * direct service call, the actor is null (system action) — exactly the
 * semantics `logSystem()` provides, unified into one call path.
 */
class BatchingService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Batch all eligible `paid` deliveries.
     *
     * @param  int|null  $branchId  Limit to one branch (Manager button).
     *                              Null = global (scheduled job).
     *                              Note: Send deliveries have no branch_id
     *                              and are therefore never touched when
     *                              $branchId is provided.
     */
    public function batchPendingDeliveries(?int $branchId = null): BatchingResult
    {
        $enabled = (bool) Setting::get('batching.enabled', true);
        if (! $enabled) {
            return BatchingResult::skipped('Batching is disabled.');
        }

        $windowMinutes = (int) Setting::get('batching.window_minutes', 10);
        $windowStart = now();
        $windowEnd = $windowStart->copy()->addMinutes($windowMinutes);

        $query = Delivery::with('pickupBranch')
            ->where('status', Delivery::STATUS_PAID)
            ->whereNull('batch_id');

        if ($branchId !== null) {
            $query->where('pickup_branch_id', $branchId);
        }

        $paid = $query->get();

        if ($paid->isEmpty()) {
            return BatchingResult::success(
                batchesCreated: 0,
                deliveriesBatched: 0,
                deliveriesSkipped: 0,
                batchIds: [],
                windowStart: $windowStart,
                windowEnd: $windowEnd,
            );
        }

        [$groups, $skippedCount] = $this->groupDeliveries($paid);

        if (empty($groups)) {
            return BatchingResult::success(
                batchesCreated: 0,
                deliveriesBatched: 0,
                deliveriesSkipped: $skippedCount,
                batchIds: [],
                windowStart: $windowStart,
                windowEnd: $windowEnd,
            );
        }

        $batchIds = [];
        $deliveriesBatched = 0;

        DB::transaction(function () use (
            $groups,
            $windowStart,
            $windowEnd,
            &$batchIds,
            &$deliveriesBatched,
        ) {
            foreach ($groups as $group) {
                $batchId = $this->generateBatchId($group['sample']);

                foreach ($group['deliveries'] as $delivery) {
                    /** @var Delivery $delivery */
                    $delivery->update([
                        'status' => Delivery::STATUS_BATCHED,
                        'batch_id' => $batchId,
                        'batch_window_start' => $windowStart,
                        'batch_window_end' => $windowEnd,
                    ]);
                    $deliveriesBatched++;
                }

                $batchIds[] = $batchId;
            }
        });

        // audit actor: authenticated user if present (Manager API path),
        // null otherwise (scheduled job / CLI / direct call). `log()`
        // accepts a nullable actor — this is the documented `logSystem`
        // shape used for background operations.
        $this->auditLogger->log(
            auth()->user(),
            action: 'batching.run_completed',
            description: sprintf(
                'Batched %d deliveries into %d batches.',
                $deliveriesBatched,
                count($batchIds),
            ),
            subject: null,
            changes: [
                'batches_created' => count($batchIds),
                'deliveries_batched' => $deliveriesBatched,
                'deliveries_skipped' => $skippedCount,
                'batch_ids' => $batchIds,
                'window_start' => $windowStart->toIso8601String(),
                'window_end' => $windowEnd->toIso8601String(),
                'branch_scope' => $branchId,
            ],
        );

        return BatchingResult::success(
            batchesCreated: count($batchIds),
            deliveriesBatched: $deliveriesBatched,
            deliveriesSkipped: $skippedCount,
            batchIds: $batchIds,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
        );
    }

    // ─────────────────────────────────────────────────────────
    // Grouping
    // ─────────────────────────────────────────────────────────

    /**
     * @return array{0: array<string, array{sample: Delivery, deliveries: Delivery[]}>, 1: int}
     */
    private function groupDeliveries(Collection $deliveries): array
    {
        $groups = [];
        $skipped = 0;

        foreach ($deliveries as $delivery) {
            /** @var Delivery $delivery */
            $key = $this->groupKey($delivery);

            if ($key === null) {
                $skipped++;

                continue;
            }

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'sample' => $delivery,
                    'deliveries' => [],
                ];
            }

            $groups[$key]['deliveries'][] = $delivery;
        }

        return [$groups, $skipped];
    }

    private function groupKey(Delivery $delivery): ?string
    {
        if ($delivery->isReceive() || $delivery->isRegional()) {
            if ($delivery->pickup_branch_id === null) {
                return null;
            }

            return "{$delivery->service_type}:branch:{$delivery->pickup_branch_id}";
        }

        if ($delivery->isSend()) {
            if (
                $delivery->destination_latitude === null ||
                $delivery->destination_longitude === null
            ) {
                return null;
            }
            $lat = round((float) $delivery->destination_latitude, 1);
            $lng = round((float) $delivery->destination_longitude, 1);

            return "send:geo:{$lat}:{$lng}";
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────
    // Batch ID
    // ─────────────────────────────────────────────────────────

    private function generateBatchId(Delivery $sample): string
    {
        $timestamp = now()->format('Ymd-His');
        $rand = strtoupper(bin2hex(random_bytes(2)));

        if ($sample->isReceive()) {
            return sprintf(
                'BATCH-%s-%s-%s',
                $this->branchCode($sample),
                $timestamp,
                $rand,
            );
        }

        if ($sample->isRegional()) {
            return sprintf(
                'BATCH-REG-%s-%s-%s',
                $this->branchCode($sample),
                $timestamp,
                $rand,
            );
        }

        return sprintf('BATCH-SEND-%s-%s', $timestamp, $rand);
    }

    private function branchCode(Delivery $delivery): string
    {
        if ($delivery->pickupBranch !== null) {
            return $delivery->pickupBranch->code;
        }

        return 'B'.$delivery->pickup_branch_id;
    }
}
