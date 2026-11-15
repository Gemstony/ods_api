<?php

namespace App\Services\Notifications;

/**
 * Canonical notification type strings (Phase 13.1).
 *
 * Stored in `notifications.type`. The Flutter UI matches on this
 * string to select the icon + routing. Never use raw strings elsewhere.
 */
enum NotificationType: string
{
    case PaymentCompleted   = 'payment.completed';
    case PaymentFailed      = 'payment.failed';
    case DeliveryAssigned   = 'delivery.assigned';
    case DeliveryAccepted   = 'delivery.accepted';
    case DeliveryPickedUp   = 'delivery.picked_up';
    case DeliveryDispatched = 'delivery.dispatched';
    case DeliveryDelivered  = 'delivery.delivered';
    case PackageReady       = 'package.ready';
    case CodeIssued         = 'code.issued';

    /**
     * Terminal events — used by the dedupe check. A second
     * payment.completed or delivery.delivered for the same delivery
     * within 5 min should not create a second row.
     *
     * `PaymentFailed` is intentionally EXCLUDED — the user may retry
     * multiple times and each failed attempt deserves its own visible
     * notification (design decision #3 from the Phase 13.1 plan).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::PaymentCompleted,
            self::DeliveryDelivered,
        ], true);
    }
    
    public function defaultPriority(): string
    {
        return match ($this) {
            self::PaymentFailed,
            self::DeliveryAssigned,
            self::CodeIssued => 'high',
            default => 'normal',
        };
    }
}