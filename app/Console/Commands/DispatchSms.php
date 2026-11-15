<?php

namespace App\Console\Commands;

use App\Services\Sms\SmsDispatcher;
use Illuminate\Console\Command;

/**
 * Dispatch pending SMS notifications via Beem (Phase 13.2).
 *
 * Usage:
 *   php artisan ods:dispatch-sms
 *   php artisan ods:dispatch-sms --limit=100
 *   php artisan ods:dispatch-sms --dry-run
 *
 * Runs every minute via scheduler; --dry-run is safe for verifying the
 * pipeline without burning SMS credits.
 */
class DispatchSms extends Command
{
    protected $signature = 'ods:dispatch-sms
                            {--limit=50 : Maximum notifications per run}
                            {--dry-run : Report only, do not send}';

    protected $description = 'Dispatch pending SMS notifications via Beem.';

    public function handle(SmsDispatcher $dispatcher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'Dispatching SMS%s (limit %d)…',
            $dryRun ? ' [DRY RUN]' : '',
            $limit,
        ));

        $result = $dispatcher->dispatchPending($limit, $dryRun);

        if ($result['reason'] !== null) {
            $this->warn('Skipped: ' . $result['reason']);
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'SMS dispatch complete: %d sent, %d failed, %d skipped.',
            $result['dispatched'],
            $result['failed'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}