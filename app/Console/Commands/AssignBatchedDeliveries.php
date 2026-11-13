<?php

namespace App\Console\Commands;

use App\Services\Assignment\AssignmentService;
use Illuminate\Console\Command;

/**
 * Assigns batched receive deliveries to waiting riders via FIFO queue.
 *
 * Usage:
 *   php artisan ods:assign-batched                # all branches
 *   php artisan ods:assign-batched --branch=5     # one branch
 *
 * Phase 10.3 will register this in the scheduler alongside
 * `ods:batch-pending`.
 */
class AssignBatchedDeliveries extends Command
{
    protected $signature = 'ods:assign-batched
                            {--branch= : Limit to a single branch ID}';

    protected $description = 'Assign batched deliveries to waiting riders (FIFO queue).';

    public function handle(AssignmentService $service): int
    {
        $branchId = $this->option('branch') !== null
            ? (int) $this->option('branch')
            : null;

        $result = $service->assignBatchedDeliveries(branchId: $branchId);

        if ($result->skipped) {
            $this->warn("Skipped: {$result->skipReason}");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Assigned %d deliveries to riders (unassigned: %d, skipped: %d).',
            $result->assignmentsMade,
            $result->deliveriesUnassigned,
            $result->deliveriesSkipped,
        ));

        if (! empty($result->assignments)) {
            $this->line('Assignments:');
            foreach ($result->assignments as $a) {
                $this->line(sprintf(
                    '  - delivery #%d → rider #%d (%s), expires %s',
                    $a['delivery_id'],
                    $a['rider_id'],
                    $a['rider_name'],
                    $a['expires_at'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
