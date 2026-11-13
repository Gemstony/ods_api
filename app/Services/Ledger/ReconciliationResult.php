<?php

namespace App\Services\Ledger;

use Carbon\Carbon;

/**
 * Result of one reconciliation run (Phase 11.4).
 *
 * Categories:
 *   reconciled      Local pending → applied provider status (change made)
 *   drift           Terminal-vs-non-terminal conflict. Reported but not applied
 *   orphans         Local pending, provider has no record
 *   unchanged       Statuses matched; no action needed
 *   failed          Provider API call failed before reconciliation started
 */
class ReconciliationResult
{
    public function __construct(
        public readonly bool $dryRun,
        public readonly ?string $error,
        public readonly Carbon $since,
        public readonly Carbon $runAt,
        public readonly int $localScanned,
        public readonly int $providerFetched,
        public readonly array $reconciled,
        public readonly array $drift,
        public readonly array $orphans,
        public readonly int $unchanged,
    ) {}

    public static function failed(string $error, Carbon $since): self
    {
        return new self(
            dryRun: false,
            error: $error,
            since: $since,
            runAt: now(),
            localScanned: 0,
            providerFetched: 0,
            reconciled: [],
            drift: [],
            orphans: [],
            unchanged: 0,
        );
    }

    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'error' => $this->error,
            'since' => $this->since->toIso8601String(),
            'run_at' => $this->runAt->toIso8601String(),
            'local_scanned' => $this->localScanned,
            'provider_fetched' => $this->providerFetched,
            'summary' => [
                'reconciled_count' => count($this->reconciled),
                'drift_count' => count($this->drift),
                'orphans_count' => count($this->orphans),
                'unchanged_count' => $this->unchanged,
            ],
            'reconciled' => $this->reconciled,
            'drift' => $this->drift,
            'orphans' => $this->orphans,
        ];
    }
}