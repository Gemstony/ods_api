<?php

namespace App\Services\Sms;

use App\Models\Notification;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Log;

/**
 * Sweeps pending SMS-channel notifications and dispatches them via
 * Beem (Phase 13.2).
 *
 * Design constraints:
 *   - Single-shot per notification — no auto-retry. Beem failures are
 *     mostly terminal (invalid phone, insufficient balance). Re-queue is
 *     a manual ops action.
 *   - Rate-limited at `config('beem.rate_limit_per_second')` — sleep
 *     between sends when the local limit is exceeded.
 *   - Sender-ID guard: if the configured sender is empty or 'INFO',
 *     refuse the whole run. Otherwise every message would burn through
 *     Beem's invalid-sender errors and mark rows failed for the wrong
 *     reason. We leave rows pending — once the sender is configured, the
 *     next run picks them up.
 *   - Per-user phone validation — skip notifications for users with no
 *     usable `phone`; mark them failed with an explicit reason.
 */
class SmsDispatcher
{
    private float $lastSendAt = 0.0;

    public function __construct(
        private readonly BeemSmsClient $client,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{dispatched: int, failed: int, skipped: int, reason: string|null}
     */
    public function dispatchPending(int $limit = 50, bool $dryRun = false): array
    {
        $senderId = (string) config('beem.sender_id', '');

        if ($senderId === '' || strtoupper($senderId) === 'INFO') {
            $reason = 'Sender ID is not configured (or is the deactivated INFO default). '
                . 'Register an approved sender in the Beem dashboard and set BEEM_SENDER_ID.';

            $this->auditLogger->logSystem(
                action: 'sms.dispatch_blocked',
                description: 'SMS dispatch skipped: ' . $reason,
            );

            return [
                'dispatched' => 0,
                'failed' => 0,
                'skipped' => 0,
                'reason' => $reason,
            ];
        }

        $pending = Notification::query()
            ->where('status', Notification::STATUS_PENDING)
            ->whereNull('dismissed_at')
            ->whereNull('read_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->filter(fn (Notification $n) => in_array('sms', $n->channels ?? [], true));

        $dispatched = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($pending as $notification) {
            /** @var Notification $notification */
            $user = User::find($notification->user_id);
            if ($user === null || $user->phone === null || $user->phone === '') {
                if (! $dryRun) {
                    $this->markFailed($notification, 'User has no usable phone number.');
                }
                $failed++;
                continue;
            }

            $destAddr = $this->normalizePhone((string) $user->phone);
            if ($destAddr === null) {
                if (! $dryRun) {
                    $this->markFailed($notification, 'Phone number is not in valid 255XXXXXXXXX format.');
                }
                $failed++;
                continue;
            }

            $message = $this->resolveMessage($notification);
            if ($message === '') {
                if (! $dryRun) {
                    $this->markFailed($notification, 'Notification has no resolvable message body.');
                }
                $failed++;
                continue;
            }

            if ($dryRun) {
                $dispatched++;
                continue;
            }

            $this->throttle();

            try {
                $result = $this->client->send(
                    destAddr: $destAddr,
                    message: $message,
                    notificationId: $notification->id,
                    triggeredBy: 'scheduler',
                );

                $notification->update([
                    'status'  => Notification::STATUS_SENT,
                    'sent_at' => now(),
                    'data'    => array_merge($notification->data ?? [], [
                        'sms_beem_request_id' => $result['beem_request_id'],
                        'sms_dest_addr_masked' => $this->maskPhone($destAddr),
                    ]),
                ]);

                $dispatched++;
            } catch (BeemSmsException $e) {
                $this->markFailed($notification, $e->getMessage(), $e->beemCode);
                $failed++;
            } catch (\Throwable $e) {
                Log::error('SMS dispatch unexpected failure', [
                    'notification_id' => $notification->id,
                    'error' => $e->getMessage(),
                ]);
                $this->markFailed($notification, 'Unexpected error: ' . $e->getMessage());
                $failed++;
            }
        }

        if ($dispatched > 0) {
            $this->auditLogger->logSystem(
                action: 'sms.dispatch_completed',
                description: sprintf(
                    'SMS dispatch: %d sent, %d failed, %d skipped.',
                    $dispatched,
                    $failed,
                    $skipped,
                ),
                changes: [
                    'dispatched' => $dispatched,
                    'failed' => $failed,
                    'skipped' => $skipped,
                ],
            );
        }

        return [
            'dispatched' => $dispatched,
            'failed' => $failed,
            'skipped' => $skipped,
            'reason' => null,
        ];
    }

    // ═════════════════════════════════════════════════════════
    // Internals
    // ═════════════════════════════════════════════════════════

    private function resolveMessage(Notification $notification): string
    {
        $data = $notification->data ?? [];

        // `sms_message` (when present) carries channel-specific content
        // — e.g. the raw verification codes for `code.issued`.
        $candidate = $data['sms_message'] ?? null;
        if (is_string($candidate) && trim($candidate) !== '') {
            return $candidate;
        }

        if (is_string($notification->body) && trim($notification->body) !== '') {
            return $notification->body;
        }

        return $notification->title ?? '';
    }

    private function throttle(): void
    {
        $rps = max(1, (int) config('beem.rate_limit_per_second', 10));
        $minInterval = 1.0 / $rps;
        $elapsed = microtime(true) - $this->lastSendAt;

        if ($elapsed < $minInterval) {
            usleep((int) (($minInterval - $elapsed) * 1_000_000));
        }
        $this->lastSendAt = microtime(true);
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

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) return '***';
        return substr($phone, 0, 3) . '***' . substr($phone, -3);
    }

    private function markFailed(Notification $notification, string $reason, ?int $beemCode = null): void
    {
        $notification->update([
            'status' => Notification::STATUS_FAILED,
            'data'   => array_merge($notification->data ?? [], [
                'sms_error'      => substr($reason, 0, 250),
                'sms_beem_code'  => $beemCode,
                'sms_failed_at'  => now()->toIso8601String(),
            ]),
        ]);
    }
}