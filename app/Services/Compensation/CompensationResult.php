<?php

namespace App\Services\Compensation;

use App\Models\Earning;

/**
 * Result of one compensation run for a single delivered delivery.
 */
class CompensationResult
{
    public function __construct(
        public readonly int $awardedCount,
        public readonly array $earnings,
        public readonly ?string $skipReason = null,
    ) {}

    public function skipped(): bool
    {
        return $this->skipReason !== null && $this->awardedCount === 0;
    }

    public function toArray(): array
    {
        return [
            'awarded_count' => $this->awardedCount,
            'skip_reason' => $this->skipReason,
            'earnings' => array_map(fn (Earning $e) => [
                'id' => $e->id,
                'user_id' => $e->user_id,
                'delivery_id' => $e->delivery_id,
                'type' => $e->type,
                'amount' => (float) $e->amount,
                'currency' => $e->currency,
                'status' => $e->status,
                'earned_at' => $e->earned_at?->toIso8601String(),
                'notes' => $e->notes,
            ], $this->earnings),
        ];
    }
}