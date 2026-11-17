<?php

namespace App\Console\Commands;

use App\Services\Assignment\AssignmentService;
use Illuminate\Console\Command;

/**
 * Returns timed-out rider assignments to the batched queue.
 */
class ReleaseExpiredAssignments extends Command
{
    protected $signature = 'ods:release-expired
                            {--branch= : Limit to a single branch ID}';

    protected $description = 'Release expired rider assignments back to the batched queue.';

    public function handle(AssignmentService $service): int
    {
        $branchId = $this->option('branch') !== null
            ? (int) $this->option('branch')
            : null;

        $released = $service->releaseExpiredAssignments(branchId: $branchId);

        $this->info("Released {$released} expired assignment(s).");

        return self::SUCCESS;
    }
}
