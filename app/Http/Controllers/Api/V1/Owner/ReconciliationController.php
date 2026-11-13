<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Ledger\ReconciliationService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner-triggered reconciliation (Phase 11.4).
 *
 *   POST /owner/ledger/reconcile         → run reconciliation
 *   GET  /owner/ledger/reconcile/last    → last run summary (from audit_logs)
 */
class ReconciliationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReconciliationService $service) {}

    public function reconcile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date', 'before_or_equal:today'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $since = isset($validated['since'])
            ? Carbon::parse($validated['since'])->startOfDay()
            : now()->subDays(7);

        $dryRun = (bool) ($validated['dry_run'] ?? false);

        $result = $this->service->reconcilePendingPayments($since, $dryRun);

        return $this->success(
            data: $result->toArray(),
            message: $dryRun ? 'Dry run complete.' : 'Reconciliation complete.',
        );
    }

    public function last(): JsonResponse
    {
        $log = AuditLog::where('action', 'reconciliation.run_completed')
            ->orderByDesc('id')
            ->first();

        if ($log === null) {
            return $this->success(
                data: ['has_run' => false],
                message: 'No reconciliation has run yet.',
            );
        }

        return $this->success(
            data: [
                'has_run' => true,
                'ran_at' => $log->created_at?->toIso8601String(),
                'actor_id' => $log->actor_id,
                'description' => $log->description,
                'changes' => $log->changes,
            ],
            message: 'OK',
        );
    }
}