<?php

namespace App\Services\Batching;

use Carbon\Carbon;

/**
 * Result of one batching run.
 *
 * Consumed by:
 *   - The artisan command (`php artisan ods:batch-pending`) — prints a summary
 *   - The Manager API endpoint (`POST /manager/batching/run`) — serializes to JSON
 *   - Tests — asserts on discrete fields
 */
class BatchingResult
{
    public bool $skipped;
    public ?string $skipReason;
    public int $batchesCreated;
    public int $deliveriesBatched;
    public int $deliveriesSkipped;
    /** @var string[] */
    public array $batchIds;
    public ?Carbon $windowStart;
    public ?Carbon $windowEnd;

    private function __construct(
        bool $skipped,
        ?string $skipReason,
        int $batchesCreated,
        int $deliveriesBatched,
        int $deliveriesSkipped,
        array $batchIds,
        ?Carbon $windowStart,
        ?Carbon $windowEnd,
    ) {
        $this->skipped = $skipped;
        $this->skipReason = $skipReason;
        $this->batchesCreated = $batchesCreated;
        $this->deliveriesBatched = $deliveriesBatched;
        $this->deliveriesSkipped = $deliveriesSkipped;
        $this->batchIds = $batchIds;
        $this->windowStart = $windowStart;
        $this->windowEnd = $windowEnd;
    }

    public static function skipped(string $reason): self
    {
        return new self(
            skipped: true,
            skipReason: $reason,
            batchesCreated: 0,
            deliveriesBatched: 0,
            deliveriesSkipped: 0,
            batchIds: [],
            windowStart: null,
            windowEnd: null,
        );
    }

    public static function success(
        int $batchesCreated,
        int $deliveriesBatched,
        int $deliveriesSkipped,
        array $batchIds,
        Carbon $windowStart,
        Carbon $windowEnd,
    ): self {
        return new self(
            skipped: false,
            skipReason: null,
            batchesCreated: $batchesCreated,
            deliveriesBatched: $deliveriesBatched,
            deliveriesSkipped: $deliveriesSkipped,
            batchIds: $batchIds,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
        );
    }

    public function toArray(): array
    {
        return [
            'skipped' => $this->skipped,
            'skip_reason' => $this->skipReason,
            'batches_created' => $this->batchesCreated,
            'deliveries_batched' => $this->deliveriesBatched,
            'deliveries_skipped' => $this->deliveriesSkipped,
            'batch_ids' => $this->batchIds,
            'window_start' => $this->windowStart?->toIso8601String(),
            'window_end' => $this->windowEnd?->toIso8601String(),
        ];
    }
}