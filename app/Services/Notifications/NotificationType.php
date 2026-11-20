<?php

namespace App\Services\Notifications;

enum NotificationType: string
{
    case PaymentCompleted        = 'payment.completed';
    case PaymentFailed           = 'payment.failed';
    case DeliveryAssigned        = 'delivery.assigned';
    case DeliveryAccepted        = 'delivery.accepted';
    case DeliveryPickedUp        = 'delivery.picked_up';
    case DeliveryDispatched      = 'delivery.dispatched';
    case DeliveryDelivered       = 'delivery.delivered';
    case RecipientDeliveryDispatched = 'delivery.recipient_dispatched';
    case RecipientDeliveryArrived = 'delivery.recipient_arrived';
    case RecipientDeliveryOnRoute = 'delivery.recipient_on_route';
    case RecipientDeliveryCompleted = 'delivery.recipient_completed';
    case PackageReady            = 'package.ready';
    case CodeIssued              = 'code.issued';
    case DisputeOpened           = 'dispute.opened';
    case DisputeResolved         = 'dispute.resolved';
    case ManualPaymentInitiated  = 'payment.manual_initiated';

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
            self::DisputeOpened,
            self::ManualPaymentInitiated => 'high',
            default => 'normal',
        };
    }
}
