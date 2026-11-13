<?php

namespace App\Services\Compensation;

use App\Models\Delivery;
use App\Models\Earning;
use App\Models\Setting;
use App\Services\Audit\AuditLogger;

/**
 * Auto-generates `earnings` rows on delivery completion (Phase 11.3).
 *
 * Called from:
 *   - RiderDeliveryController::deliver       (local — pickup → delivered)
 *   - RegionalDispatcherController::confirmArrival  (regional — dispatched → delivered)
 *
 * Award types (per ODS report):
 *   rider_baseline       80% of ods_delivery_fee — auto for every local delivery
 *   rider_bonus          +10% — opt-in, only when delivery was in a batch of >1
 *   dispatcher_flat      fixed amount — regional default model
 *   dispatcher_percent   % of ods_delivery_fee — alternate regional model
 *   dispatcher_shift     shift allowance — manual Owner-only operation
 *   adjustment           manual correction — Owner-only
 *
 * The service is idempotent: `(delivery_id, user_id, type)` is checked
 * before every insert. Calling twice on the same delivery is a safe no-op.
 *
 * Design note: compensation runs AFTER the delivery row is committed. A
 * failure here is logged but never rolls back the delivery. This keeps
 * the delivery-completion endpoint resilient — money can be re-awarded
 * from a manual admin command; status reversion is expensive.
 */
class CompensationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Award all applicable earnings for a delivered delivery.
     */
    public function awardForDeliveredDelivery(Delivery $delivery): CompensationResult
    {
        if ($delivery->status !== Delivery::STATUS_DELIVERED) {
            return new CompensationResult(0, [], 'Delivery is not in delivered state.');
        }

        if ($delivery->delivered_at === null) {
            return new CompensationResult(0, [], 'Delivery has no delivered_at timestamp.');
        }

        if (! (bool) Setting::get('compensation.enabled', true)) {
            return new CompensationResult(0, [], 'Compensation is disabled.');
        }

        $awarded = [];

        if ($delivery->isLocal()) {
            $awarded = array_merge($awarded, $this->awardLocal($delivery));
        } elseif ($delivery->isRegional()) {
            $awarded = array_merge($awarded, $this->awardRegional($delivery));
        } else {
            return new CompensationResult(0, [], 'Unknown service type.');
        }

        if (! empty($awarded)) {
            $this->auditLogger->log(
                auth()->user(),
                action: 'compensation.awarded',
                description: sprintf(
                    'Awarded %d earning(s) for delivered %s delivery %s.',
                    count($awarded),
                    $delivery->service_type,
                    $delivery->tracking_id,
                ),
                subject: $delivery,
                changes: [
                    'earnings' => array_map(fn (Earning $e) => [
                        'id' => $e->id,
                        'user_id' => $e->user_id,
                        'type' => $e->type,
                        'amount' => (float) $e->amount,
                        'status' => $e->status,
                    ], $awarded),
                ],
            );
        }

        return new CompensationResult(count($awarded), $awarded);
    }

    // ═════════════════════════════════════════════════════════
    // Local (Receive / Send)
    // ═════════════════════════════════════════════════════════

    private function awardLocal(Delivery $delivery): array
    {
        if ($delivery->rider_id === null) {
            return [];
        }

        $awarded = [];

        $baseline = $this->awardRiderBaseline($delivery);
        if ($baseline !== null) {
            $awarded[] = $baseline;
        }

        if ((bool) Setting::get('compensation.rider_bonus_enabled', false)) {
            $bonus = $this->awardRiderBonus($delivery);
            if ($bonus !== null) {
                $awarded[] = $bonus;
            }
        }

        return $awarded;
    }

    private function awardRiderBaseline(Delivery $delivery): ?Earning
    {
        if ($this->alreadyAwarded($delivery->id, $delivery->rider_id, Earning::TYPE_RIDER_BASELINE)) {
            return null;
        }

        $basis = (float) ($delivery->ods_delivery_fee ?? 0);
        if ($basis <= 0) {
            return null;
        }

        $percent = (float) Setting::get('compensation.rider_baseline_percent', 80);
        if ($percent <= 0) {
            return null;
        }

        $amount = round($basis * ($percent / 100), 2);
        if ($amount <= 0) {
            return null;
        }

        return Earning::create([
            'user_id' => $delivery->rider_id,
            'delivery_id' => $delivery->id,
            'type' => Earning::TYPE_RIDER_BASELINE,
            'amount' => $amount,
            'currency' => $delivery->currency ?? 'TZS',
            'status' => $this->initialStatus(),
            'earned_at' => $delivery->delivered_at ?? now(),
            'notes' => sprintf('%s%% of ODS fee (%.2f TZS).', $percent, $basis),
        ]);
    }

    private function awardRiderBonus(Delivery $delivery): ?Earning
    {
        if ($this->alreadyAwarded($delivery->id, $delivery->rider_id, Earning::TYPE_RIDER_BONUS)) {
            return null;
        }

        if ($delivery->batch_id === null) {
            return null;
        }

        $batchSize = Delivery::where('batch_id', $delivery->batch_id)->count();
        if ($batchSize < 2) {
            return null;
        }

        $basis = (float) ($delivery->ods_delivery_fee ?? 0);
        if ($basis <= 0) {
            return null;
        }

        $percent = (float) Setting::get('compensation.rider_bonus_percent', 10);
        if ($percent <= 0) {
            return null;
        }

        $amount = round($basis * ($percent / 100), 2);
        if ($amount <= 0) {
            return null;
        }

        return Earning::create([
            'user_id' => $delivery->rider_id,
            'delivery_id' => $delivery->id,
            'type' => Earning::TYPE_RIDER_BONUS,
            'amount' => $amount,
            'currency' => $delivery->currency ?? 'TZS',
            'status' => $this->initialStatus(),
            'earned_at' => $delivery->delivered_at ?? now(),
            'notes' => sprintf(
                '%s%% multi-order bonus (batch of %d, ODS fee %.2f TZS).',
                $percent,
                $batchSize,
                $basis,
            ),
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // Regional
    // ═════════════════════════════════════════════════════════

    private function awardRegional(Delivery $delivery): array
    {
        if ($delivery->dispatcher_id === null) {
            return [];
        }

        $model = (string) Setting::get('compensation.dispatcher_model', 'flat');

        return match ($model) {
            'flat' => $this->awardDispatcherFlat($delivery),
            'percent' => $this->awardDispatcherPercent($delivery),
            default => [],
        };
    }

    private function awardDispatcherFlat(Delivery $delivery): array
    {
        if ($this->alreadyAwarded($delivery->id, $delivery->dispatcher_id, Earning::TYPE_DISPATCHER_FLAT)) {
            return [];
        }

        $amount = (float) Setting::get('compensation.dispatcher_flat_amount_tsh', 2000);
        if ($amount <= 0) {
            return [];
        }

        return [Earning::create([
            'user_id' => $delivery->dispatcher_id,
            'delivery_id' => $delivery->id,
            'type' => Earning::TYPE_DISPATCHER_FLAT,
            'amount' => $amount,
            'currency' => $delivery->currency ?? 'TZS',
            'status' => $this->initialStatus(),
            'earned_at' => $delivery->delivered_at ?? now(),
            'notes' => 'Flat per dispatched regional package.',
        ])];
    }

    private function awardDispatcherPercent(Delivery $delivery): array
    {
        if ($this->alreadyAwarded($delivery->id, $delivery->dispatcher_id, Earning::TYPE_DISPATCHER_PERCENT)) {
            return [];
        }

        $basis = (float) ($delivery->ods_delivery_fee ?? 0);
        if ($basis <= 0) {
            return [];
        }

        $percent = (float) Setting::get('compensation.dispatcher_percent', 10);
        if ($percent <= 0) {
            return [];
        }

        $amount = round($basis * ($percent / 100), 2);
        if ($amount <= 0) {
            return [];
        }

        return [Earning::create([
            'user_id' => $delivery->dispatcher_id,
            'delivery_id' => $delivery->id,
            'type' => Earning::TYPE_DISPATCHER_PERCENT,
            'amount' => $amount,
            'currency' => $delivery->currency ?? 'TZS',
            'status' => $this->initialStatus(),
            'earned_at' => $delivery->delivered_at ?? now(),
            'notes' => sprintf('Dispatcher commission: %s%% of ODS fee (%.2f TZS).', $percent, $basis),
        ])];
    }

    // ═════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════

    private function alreadyAwarded(int $deliveryId, ?int $userId, string $type): bool
    {
        if ($userId === null) {
            return true;
        }

        return Earning::where('delivery_id', $deliveryId)
            ->where('user_id', $userId)
            ->where('type', $type)
            ->exists();
    }

    private function initialStatus(): string
    {
        $requireApproval = (bool) Setting::get('compensation.require_approval', true);
        return $requireApproval ? Earning::STATUS_PENDING : Earning::STATUS_APPROVED;
    }
}