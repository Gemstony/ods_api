<?php

namespace App\Services\Ledger;

use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use App\Services\ClickPesa\ClickPesaClient;
use App\Services\ClickPesa\ClickPesaException;
use App\Services\ClickPesa\PaymentStatus;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentStatusMapper;
use Carbon\Carbon;

/**
 * Compares local pending payments against ClickPesa's query-all feed
 * and (optionally) applies the provider's status (Phase 11.4).
 *
 * Strategy:
 *   1. Load local payments in {pending, processing} since `$since`.
 *   2. Fetch ClickPesa `query-all` (paginated, up to 10 pages = 1000 rows).
 *   3. Diff by `orderReference`.
 *   4. For each mismatch, call PaymentService::applyStatus (state machine,
 *      terminal guard, delivery-paid transition, audit).
 *   5. Record a `verify` PaymentTransaction per applied change.
 *
 * Handles three drift classes:
 *   - Reconciled: local pending, provider non-pending → state changed
 *   - Drift: local terminal, provider says different → reported, NOT applied
 *   - Orphans: local pending, provider has no record → reported, NOT changed
 *     (a pending USSD that never completed; will be cancelled by a future
 *     expiry job)
 *
 * Reconciliation runs from:
 *   - `POST /owner/ledger/reconcile` (Owner button)
 *   - `php artisan ods:reconcile-payments` (CLI + future scheduler)
 */
class ReconciliationService
{
    private const MAX_PAGES = 10;
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly ClickPesaClient $client,
        private readonly PaymentService $payments,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function reconcilePendingPayments(
        ?Carbon $since = null,
        bool $dryRun = false,
    ): ReconciliationResult {
        $since ??= now()->subDays(7);

        $local = Payment::whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->where('created_at', '>=', $since)
            ->get();

        if ($local->isEmpty()) {
            return $this->persistAndReturn(
                new ReconciliationResult(
                    dryRun: $dryRun,
                    error: null,
                    since: $since,
                    runAt: now(),
                    localScanned: 0,
                    providerFetched: 0,
                    reconciled: [],
                    drift: [],
                    orphans: [],
                    unchanged: 0,
                )
            );
        }

        try {
            $providerItems = $this->fetchProviderPayments($since);
        } catch (ClickPesaException $e) {
            return ReconciliationResult::failed(
                'Provider query failed: ' . $e->getMessage(),
                $since,
            );
        }

        $providerByRef = [];
        foreach ($providerItems as $item) {
            $ref = $item['orderReference'] ?? $item['order_reference'] ?? null;
            if (is_string($ref) && $ref !== '') {
                $providerByRef[$ref] = $item;
            }
        }

        $reconciled = [];
        $drift = [];
        $orphans = [];
        $unchanged = 0;

        foreach ($local as $payment) {
            /** @var Payment $payment */
            $remote = $providerByRef[$payment->reference] ?? null;

            if ($remote === null) {
                $orphans[] = $this->orphanEntry($payment);
                continue;
            }

            $remoteStatus = PaymentStatus::fromString($remote['status'] ?? null);
            $newLocalStatus = PaymentStatusMapper::toPaymentStatus($remoteStatus);

            if ($newLocalStatus === null || $newLocalStatus === $payment->status) {
                $unchanged++;
                continue;
            }

            if (! PaymentStatusMapper::canTransition($payment->status, $newLocalStatus)) {
                $drift[] = $this->driftEntry($payment, $remoteStatus);
                continue;
            }

            if ($dryRun) {
                $reconciled[] = $this->reconciledEntry($payment, $newLocalStatus, $remoteStatus, applied: false);
                continue;
            }

            $before = $payment->status;
            $changed = $this->payments->applyStatus($payment, $remoteStatus, $remote);

            if ($changed) {
                $this->payments->recordTransaction(
                    payment: $payment,
                    type: 'verify',
                    status: 'success',
                    request: ['trigger' => 'reconcile'],
                    response: $remote,
                    error: null,
                );

                $reconciled[] = $this->reconciledEntry(
                    $payment,
                    $newLocalStatus,
                    $remoteStatus,
                    applied: true,
                    from: $before,
                );
            } else {
                // applyStatus rejected it (terminal guard fired between
                // our pre-check and the lock).
                $payment->refresh();
                if ($payment->status === $newLocalStatus) {
                    $unchanged++;
                } else {
                    $drift[] = $this->driftEntry($payment, $remoteStatus);
                }
            }
        }

        return $this->persistAndReturn(new ReconciliationResult(
            dryRun: $dryRun,
            error: null,
            since: $since,
            runAt: now(),
            localScanned: $local->count(),
            providerFetched: count($providerItems),
            reconciled: $reconciled,
            drift: $drift,
            orphans: $orphans,
            unchanged: $unchanged,
        ));
    }

    // ═════════════════════════════════════════════════════════
    // Provider fetch
    // ═════════════════════════════════════════════════════════

    private function fetchProviderPayments(Carbon $since): array
    {
        $items = [];
        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $response = $this->client->queryAllPayments([
                'startDate' => $since->toDateString(),
                'endDate' => now()->toDateString(),
                'page' => $page,
                'perPage' => self::PAGE_SIZE,
            ]);

            $batch = $response['data'] ?? $response['items'] ?? [];
            if (! is_array($batch) || empty($batch)) {
                break;
            }

            $items = array_merge($items, $batch);

            $pagination = $response['pagination'] ?? [];
            $totalPages = (int) ($pagination['totalPages'] ?? $pagination['last_page'] ?? 1);
            if ($page >= $totalPages) {
                break;
            }
            $page++;
        }

        return $items;
    }

    // ═════════════════════════════════════════════════════════
    // Result shaping + audit
    // ═════════════════════════════════════════════════════════

    private function reconciledEntry(
        Payment $payment,
        string $newStatus,
        PaymentStatus $remoteStatus,
        bool $applied,
        ?string $from = null,
    ): array {
        return [
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'delivery_id' => $payment->delivery_id,
            'from_status' => $from ?? $payment->status,
            'to_status' => $newStatus,
            'provider_status' => $remoteStatus->value,
            'applied' => $applied,
        ];
    }

    private function driftEntry(Payment $payment, PaymentStatus $remoteStatus): array
    {
        return [
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'delivery_id' => $payment->delivery_id,
            'local_status' => $payment->status,
            'provider_status' => $remoteStatus->value,
            'reason' => 'Local state is terminal; provider would regress it.',
        ];
    }

    private function orphanEntry(Payment $payment): array
    {
        return [
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'delivery_id' => $payment->delivery_id,
            'local_status' => $payment->status,
            'initiated_at' => $payment->initiated_at?->toIso8601String(),
        ];
    }

    private function persistAndReturn(ReconciliationResult $result): ReconciliationResult
    {
        $this->auditLogger->log(
            auth()->user(),
            action: 'reconciliation.run_completed',
            description: sprintf(
                'Reconciled payments since %s: %d applied, %d drift, %d orphans, %d unchanged.%s',
                $result->since->toDateString(),
                count($result->reconciled),
                count($result->drift),
                count($result->orphans),
                $result->unchanged,
                $result->dryRun ? ' (dry-run)' : '',
            ),
            subject: null,
            changes: [
                'dry_run' => $result->dryRun,
                'local_scanned' => $result->localScanned,
                'provider_fetched' => $result->providerFetched,
                'reconciled_count' => count($result->reconciled),
                'drift_count' => count($result->drift),
                'orphans_count' => count($result->orphans),
                'unchanged_count' => $result->unchanged,
            ],
        );

        return $result;
    }
}