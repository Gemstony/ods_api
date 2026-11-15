<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\NotificationSmsLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Sms\BeemSmsClient;
use App\Services\Sms\BeemSmsException;
use Illuminate\Console\Command;

/**
 * Poll Beem delivery reports for SMS sent via ODS (Phase 13.2).
 *
 * Usage:
 *   php artisan ods:reconcile-sms
 *   php artisan ods:reconcile-sms --since=48
 *
 * Scans `notifications` where status = sent, sent_at >= now() - $since
 * hours, and data.sms_beem_request_id is set. Queries Beem's delivery
 * reports endpoint for each; stores the result in `data.sms_status`.
 *
 * Safe to run repeatedly — Beem reports are idempotent. Should be
 * scheduled at least 5 minutes after each send (Beem's recommendation);
 * our daily 03:30 run covers all messages from the previous day.
 */
class ReconcileSmsDeliveryReports extends Command
{
    protected $signature = 'ods:reconcile-sms
                            {--since=48 : Only check messages sent in the last N hours}';

    protected $description = 'Fetch Beem delivery reports for SMS notifications.';

    public function handle(BeemSmsClient $client, AuditLogger $auditLogger): int
    {
        $sinceHours = max(1, (int) $this->option('since'));
        $cutoff = now()->subHours($sinceHours);

        $candidates = Notification::query()
            ->where('status', Notification::STATUS_SENT)
            ->where('sent_at', '>=', $cutoff)
            ->whereNotNull('data->sms_beem_request_id')
            ->orderBy('sent_at')
            ->limit(200)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No SMS notifications to reconcile.');
            return self::SUCCESS;
        }

        $checked = 0;
        $delivered = 0;
        $undelivered = 0;

        foreach ($candidates as $notification) {
            /** @var Notification $notification */
            $data = $notification->data ?? [];
            $requestId = $data['sms_beem_request_id'] ?? null;
            $user = User::find($notification->user_id);
            $phone = $user?->phone ?? null;

            if (! is_string($requestId) || $requestId === '' || $phone === null || $phone === '') {
                continue;
            }

            $destAddr = $this->normalizePhone((string) $phone);
            if ($destAddr === null) continue;

            try {
                $report = $client->deliveryReport(
                    destAddr: $destAddr,
                    beemRequestId: $requestId,
                    notificationId: $notification->id,
                    triggeredBy: 'scheduler',
                );
            } catch (BeemSmsException $e) {
                $this->warn(sprintf(
                    'Report check failed for notification #%d: %s',
                    $notification->id,
                    $e->getMessage(),
                ));
                continue;
            }

            $checked++;
            $status = $report['status'];
            if ($status === 'DELIVERED') $delivered++;
            if ($status === 'UNDELIVERED') $undelivered++;

            $notification->update([
                'data' => array_merge($data, [
                    'sms_status'          => $status,
                    'sms_status_checked'  => now()->toIso8601String(),
                ]),
            ]);
        }

        $auditLogger->logSystem(
            action: 'sms.delivery_reports_reconciled',
            description: sprintf(
                'SMS reconciliation: %d checked, %d delivered, %d undelivered.',
                $checked,
                $delivered,
                $undelivered,
            ),
            changes: [
                'checked' => $checked,
                'delivered' => $delivered,
                'undelivered' => $undelivered,
                'since_hours' => $sinceHours,
            ],
        );

        $this->info(sprintf(
            'Reconciled: %d checked, %d delivered, %d undelivered.',
            $checked,
            $delivered,
            $undelivered,
        ));

        return self::SUCCESS;
    }

    private function normalizePhone(string $raw): ?string
    {
        $t = preg_replace('/\s+/', '', $raw);
        if ($t === null) return null;
        if (str_starts_with($t, '+255') && strlen($t) === 13) {
            $t = substr($t, 1);
        } elseif (str_starts_with($t, '0') && strlen($t) === 10) {
            $t = '255' . substr($t, 1);
        }
        return preg_match('/^255\d{9}$/', $t) === 1 ? $t : null;
    }
}