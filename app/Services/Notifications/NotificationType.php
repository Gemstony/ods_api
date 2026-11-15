<?php

namespace App\Services\Notifications;

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
    case DisputeOpened      = 'dispute.opened';
    case DisputeResolved    = 'dispute.resolved';

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
            self::CodeIssued,
            self::DisputeOpened => 'high',
            default => 'normal',
        };
    }
}