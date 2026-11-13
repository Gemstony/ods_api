<?php

namespace App\Console\Commands;

use App\Services\Ledger\ReconciliationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Reconcile local pending payments against ClickPesa's query-all feed
 * (Phase 11.4).
 *
 * Usage:
 *   php artisan ods:reconcile-payments
 *   php artisan ods:reconcile-payments --since=2026-11-01
 *   php artisan ods:reconcile-payments --dry-run
 */
class ReconcilePayments extends Command
{
    protected $signature = 'ods:reconcile-payments
                            {--since= : Start date (default: 7 days ago)}
                            {--dry-run : Report changes without applying them}';

    protected $description = 'Reconcile local pending payments against ClickPesa.';

    public function handle(ReconciliationService $service): int
    {
        $since = $this->option('since') !== null
            ? Carbon::parse($this->option('since'))->startOfDay()
            : now()->subDays(7);
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'Reconciling payments since %s%s...',
            $since->toDateTimeString(),
            $dryRun ? ' (dry-run)' : '',
        ));

        $result = $service->reconcilePendingPayments($since, $dryRun);

        if ($result->error !== null) {
            $this->error('✗ ' . $result->error);
            return self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf('Local pending/processing: %d', $result->localScanned));
        $this->line(sprintf('Provider records fetched: %d', $result->providerFetched));
        $this->line('────────────────────────────────────────────');

        $this->info(sprintf(
            '✓ Reconciled: %d',
            count($result->reconciled),
        ));
        foreach ($result->reconciled as $r) {
            $this->line(sprintf(
                '    %s: %s → %s (provider: %s)%s',
                $r['reference'],
                $r['from_status'],
                $r['to_status'],
                $r['provider_status'],
                $r['applied'] ? '' : ' [DRY-RUN]',
            ));
        }

        if (! empty($result->drift)) {
            $this->warn(sprintf('⊘ Drift: %d', count($result->drift)));
            foreach ($result->drift as $d) {
                $this->line(sprintf(
                    '    %s: local=%s vs provider=%s (%s)',
                    $d['reference'],
                    $d['local_status'],
                    $d['provider_status'],
                    $d['reason'],
                ));
            }
        }

        if (! empty($result->orphans)) {
            $this->warn(sprintf('? Orphans (provider has no record): %d', count($result->orphans)));
            foreach ($result->orphans as $o) {
                $this->line(sprintf(
                    '    %s: local=%s',
                    $o['reference'],
                    $o['local_status'],
                ));
            }
        }

        $this->line(sprintf('○ Unchanged: %d', $result->unchanged));

        return self::SUCCESS;
    }
}