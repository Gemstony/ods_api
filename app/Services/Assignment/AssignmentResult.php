<?php

namespace App\Services\Assignment;

use Carbon\Carbon;

/**
 * Result of one assignment run.
 */
class AssignmentResult
{
    public bool $skipped;

    public ?string $skipReason;

    public int $assignmentsMade;

    public int $deliveriesUnassigned;

    public int $deliveriesSkipped;

    /** @var array<int, array{delivery_id:int, rider_id:int, rider_name:string, expires_at:string}> */
    public array $assignments;

    public ?Carbon $runAt;

    private function __construct(
        bool $skipped,
        ?string $skipReason,
        int $assignmentsMade,
        int $deliveriesUnassigned,
        int $deliveriesSkipped,
        array $assignments,
        ?Carbon $runAt,
    ) {
        $this->skipped = $skipped;
        $this->skipReason = $skipReason;
        $this->assignmentsMade = $assignmentsMade;
        $this->deliveriesUnassigned = $deliveriesUnassigned;
        $this->deliveriesSkipped = $deliveriesSkipped;
        $this->assignments = $assignments;
        $this->runAt = $runAt;
    }

    public static function skipped(string $reason): self
    {
        return new self(
            skipped: true,
            skipReason: $reason,
            assignmentsMade: 0,
            deliveriesUnassigned: 0,
            deliveriesSkipped: 0,
            assignments: [],
            runAt: null,
        );
    }

    public static function success(
        int $assignmentsMade,
        int $deliveriesUnassigned,
        int $deliveriesSkipped,
        array $assignments,
        Carbon $runAt,
    ): self {
        return new self(
            skipped: false,
            skipReason: null,
            assignmentsMade: $assignmentsMade,
            deliveriesUnassigned: $deliveriesUnassigned,
            deliveriesSkipped: $deliveriesSkipped,
            assignments: $assignments,
            runAt: $runAt,
        );
    }

    public function toArray(): array
    {
        return [
            'skipped' => $this->skipped,
            'skip_reason' => $this->skipReason,
            'assignments_made' => $this->assignmentsMade,
            'deliveries_unassigned' => $this->deliveriesUnassigned,
            'deliveries_skipped' => $this->deliveriesSkipped,
            'assignments' => $this->assignments,
            'run_at' => $this->runAt?->toIso8601String(),
        ];
    }
}
