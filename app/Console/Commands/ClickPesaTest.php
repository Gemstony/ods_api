<?php

namespace App\Console\Commands;

use App\Services\ClickPesa\ClickPesaClient;
use App\Services\ClickPesa\ClickPesaException;
use App\Services\ClickPesa\UssdPushRequest;
use Illuminate\Console\Command;
use Throwable;

/**
 * Smoke test for the ClickPesa integration (Phase 11.1).
 *
 * Safe operations only — auth and (optionally) preview. Neither moves
 * money. Use this to verify credentials and endpoint connectivity
 * before wiring Phase 11.2's payment flow.
 *
 * Usage:
 *   php artisan clickpesa:test
 *   php artisan clickpesa:test --preview
 *   php artisan clickpesa:test --preview --phone=255712345678 --amount=1000
 */
class ClickPesaTest extends Command
{
    protected $signature = 'clickpesa:test
                            {--preview : Also test a preview-ussd-push call (safe, no charge)}
                            {--phone=255712345678 : Phone for preview (255XXXXXXXXX)}
                            {--amount=1000 : Amount for preview (TZS)}';

    protected $description = 'Verify ClickPesa credentials and connectivity (auth + optional preview).';

    public function handle(ClickPesaClient $client): int
    {
        $this->info('Testing ClickPesa connection…');
        $this->newLine();

        // ─── 1. Auth ────────────────────────────────────────
        try {
            $token = $client->ensureFreshToken();
            $this->info('✓ Token obtained');
            $this->line('  Length: ' . strlen($token) . ' chars');
            $this->line('  Prefix: ' . substr($token, 0, 20) . '…');
        } catch (ClickPesaException $e) {
            $this->error('✗ Auth failed: ' . $e->getMessage());
            $this->line('  HTTP: ' . ($e->httpStatus ?? 'network'));
            if ($e->logId) {
                $this->line('  Log: #' . $e->logId);
            }
            return self::FAILURE;
        }

        if (! $this->option('preview')) {
            $this->newLine();
            $this->info('Auth OK. Run with --preview to test the payment endpoint.');
            return self::SUCCESS;
        }

        // ─── 2. Preview ─────────────────────────────────────
        $this->newLine();
        $this->info('Testing preview-ussd-push…');

        $orderRef = 'TEST-' . now()->format('His');
        $amount = (float) $this->option('amount');
        $phone = (string) $this->option('phone');

        try {
            $result = $client->previewUssdPush(
                UssdPushRequest::create(
                    orderReference: $orderRef,
                    amount: $amount,
                    phoneNumber: $phone,
                ),
            );

            $this->info('✓ Preview succeeded');
            $channels = $result['channels'] ?? [];
            if (is_array($channels) && count($channels) > 0) {
                $rows = [];
                foreach ($channels as $c) {
                    $rows[] = [
                        $c['name'] ?? ($c['channel'] ?? '-'),
                        ($c['available'] ?? false) ? 'yes' : 'no',
                        $c['message'] ?? '-',
                    ];
                }
                $this->table(['Channel', 'Available', 'Message'], $rows);
            } else {
                $this->line('  No channels returned: ' . json_encode($result));
            }
        } catch (ClickPesaException $e) {
            $this->error('✗ Preview failed: ' . $e->getMessage());
            $this->line('  HTTP: ' . ($e->httpStatus ?? 'network'));
            if ($e->responseBody) {
                $this->line('  Body: ' . json_encode($e->responseBody));
            }
            if ($e->logId) {
                $this->line('  Log: #' . $e->logId);
            }
            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('✗ Unexpected error: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All checks passed.');
        return self::SUCCESS;
    }
}