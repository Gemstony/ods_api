<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\ClickPesa\PaymentStatus;

/**
 * Maps ClickPesa statuses onto Payment model statuses (Phase 11.2).
 *
 * Mapping (from ClickPesa docs):
 *   PENDING / PROCESSING → Payment::STATUS_PENDING / STATUS_PROCESSING
 *   SUCCESS / SETTLED    → Payment::STATUS_COMPLETED
 *   FAILED               → Payment::STATUS_FAILED
 *   UNKNOWN              → null (caller leaves state untouched)
 *
 * SUCCESS and SETTLED both count as success — the difference is only
 * whether funds have hit the merchant's ClickPesa balance; both mean
 * the money has left the customer.
 */
class PaymentStatusMapper
{
    public static function toPaymentStatus(PaymentStatus $clickPesa): ?string
    {
        return match ($clickPesa) {
            PaymentStatus::PENDING    => Payment::STATUS_PENDING,
            PaymentStatus::PROCESSING => Payment::STATUS_PROCESSING,
            PaymentStatus::SUCCESS    => Payment::STATUS_COMPLETED,
            PaymentStatus::SETTLED    => Payment::STATUS_COMPLETED,
            PaymentStatus::FAILED     => Payment::STATUS_FAILED,
            PaymentStatus::UNKNOWN    => null,
        };
    }

    /**
     * Whether transitioning from $from to $to is allowed. Guards the
     * state machine against late webhooks reversing terminal states.
     *
     * Rules:
     *   completed → * : never (final)
     *   cancelled → * : never (final)
     *   refunded  → * : never (final)
     *   failed    → completed : allowed (retry success with same ref)
     *   failed    → failed   : no-op
     *   *         → *        : allowed
     */
    public static function canTransition(?string $from, string $to): bool
    {
        if ($from === null) {
            return true;
        }
        if ($from === $to) {
            return false; // no-op
        }
        if (in_array($from, [
            Payment::STATUS_COMPLETED,
            Payment::STATUS_CANCELLED,
            Payment::STATUS_REFUNDED,
        ], true)) {
            return false; // terminal
        }
        return true;
    }
}