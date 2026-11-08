<?php

namespace App\Console\Commands;

use App\Services\Batching\BatchingService;
use Illuminate\Console\Command;

/**
 * Groups all currently `paid` deliveries into batches.
 *
 * Usage:
 *   php artisan ods:batch-pending                 # all branches
 *   php artisan ods:batch-pending --branch=5      # one branch
 *
 * Phase 10.3 will register this in the scheduler every N minutes
 * (`->withSchedule(fn (Schedule $s) => $s->command('ods:batch-pending')->everyFiveMinutes())`).
 */
class BatchPendingDeliveries extends Command
{
    protected $signature = 'ods:batch-pending
                            {--branch= : Limit to a single branch ID}';

    protected $description = 'Group paid deliveries into batches for rider/dispatcher assignment.';

    public function handle(BatchingService $service): int
    {
        $branchId = $this->option('branch') !== null
            ? (int) $this->option('branch')
            : null;

        $result = $service->batchPendingDeliveries(branchId: $branchId);

        if ($result->skipped) {
            $this->warn("Skipped: {$result->skipReason}");
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Batched %d deliveries into %d batches (window: %s → %s).',
            $result->deliveriesBatched,
            $result->batchesCreated,
            $result->windowStart?->toDateTimeString() ?? 'n/a',
            $result->windowEnd?->toDateTimeString() ?? 'n/a',
        ));

        if ($result->deliveriesSkipped > 0) {
            $this->warn(sprintf(
                '%d paid delivery(ies) were skipped (missing branch or GPS).',
                $result->deliveriesSkipped,
            ));
        }

        if (! empty($result->batchIds)) {
            $this->line('Batch IDs:');
            foreach ($result->batchIds as $id) {
                $this->line("  - {$id}");
            }
        }

        return self::SUCCESS;
    }
}