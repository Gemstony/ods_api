<?php

namespace App\Services\Notifications;

use App\Models\Delivery;
use App\Models\Dispute;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Creates user-facing notification rows (Phase 13.1).
 *
 * Distinction from AuditLogger: AuditLog is a compliance trail (who did
 * what), never shown in the app. Notifications are user-facing — they
 * have read_at / dismissed_at state and deep-link payloads.
 *
 * Every method is best-effort: exceptions are logged but never bubble
 * up to the caller. A failed notification never breaks the underlying
 * business operation (payment, delivery transition, etc.).
 */
class NotificationService
{
    // ═════════════════════════════════════════════════════════
    // Core primitives
    // ═════════════════════════════════════════════════════════

    public function notify(
        User $user,
        NotificationType $type,
        string $title,
        ?string $body = null,
        array $data = [],
        array $channels = ['in_app', 'sms'],
        ?string $priority = null,
    ): ?Notification {
        try {
            // Dedupe terminal events within a 5-minute window.
            $deliveryId = isset($data['delivery_id']) ? (int) $data['delivery_id'] : null;
            if ($type->isTerminal() && $this->hasRecent($user->id, $type, $deliveryId)) {
                return null;
            }

            // All user-facing notifications are sent in-app and by SMS.
            // Keeping this policy here prevents an event helper from
            // accidentally marking an unsent SMS as sent by omitting its
            // channels argument.
            $channels = array_values(array_unique([...$channels, 'in_app', 'sms']));

            $notification = Notification::send(
                user: $user,
                type: $type->value,
                title: $title,
                body: $body,
                data: $data,
                channels: $channels,
                priority: $priority ?? $type->defaultPriority(),
            );

            return $notification;
        } catch (\Throwable $e) {
            Log::error('Notification write failed', [
                'user_id' => $user->id,
                'type' => $type->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function notifyMany(
        iterable $users,
        NotificationType $type,
        string $title,
        ?string $body = null,
        array $data = [],
        array $channels = ['in_app', 'sms'],
        ?string $priority = null,
    ): void {
        foreach ($users as $user) {
            $this->notify($user, $type, $title, $body, $data, $channels, $priority);
        }
    }

    // ═════════════════════════════════════════════════════════
    // Typed event helpers — called from the write-side controllers
    // ═════════════════════════════════════════════════════════

    public function paymentCompleted(Payment $payment): void
    {
        $delivery = $payment->delivery;
        if ($delivery === null) {
            return;
        }
        $distributor = User::find($delivery->distributor_id);
        if ($distributor === null) {
            return;
        }

        $amountLabel = $payment->currency.' '
            .number_format((float) $payment->amount, 0);

        $this->notify(
            user: $distributor,
            type: NotificationType::PaymentCompleted,
            title: 'Payment received',
            body: "Your payment of {$amountLabel} for "
            ."{$delivery->tracking_id} was received. The delivery "
            .'is now ready for pickup.',
            data: $this->deliveryPayload($delivery),
        );
    }

    public function paymentFailed(Payment $payment): void
    {
        $delivery = $payment->delivery;
        if ($delivery === null) {
            return;
        }
        $distributor = User::find($delivery->distributor_id);
        if ($distributor === null) {
            return;
        }

        $this->notify(
            user: $distributor,
            type: NotificationType::PaymentFailed,
            title: 'Payment failed',
            body: 'Payment for '.$delivery->tracking_id
            .' failed: '.($payment->failure_reason ?? 'unknown error')
            .'. Tap to try again.',
            data: $this->deliveryPayload($delivery),
        );
    }

    public function manualPaymentInitiated(Payment $payment): void
    {
        $delivery = $payment->delivery;
        if ($delivery === null) {
            return;
        }

        // Find the Manager of the delivery's pickup branch.
        $branchId = $delivery->pickup_branch_id;
        $managers = collect();

        if ($branchId !== null) {
            $managers = User::query()
                ->where('branch_id', $branchId)
                ->where('status', User::STATUS_ACTIVE)
                ->whereHas('role', fn ($q) => $q->where('name', Role::MANAGER))
                ->get();
        }

        // If no branch (Send delivery) or no Manager, notify Owners instead.
        if ($managers->isEmpty()) {
            $managers = User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->whereHas('role', fn ($q) => $q->where('name', Role::OWNER))
                ->get();
        }

        if ($managers->isEmpty()) {
            return;
        }

        $amountLabel = $payment->currency.' '
            .number_format((float) $payment->amount, 0);

        $this->notifyMany(
            users: $managers,
            type: NotificationType::ManualPaymentInitiated,
            title: 'Manual payment awaiting approval',
            body: $amountLabel.' '.$payment->method.' payment recorded '
                .'for '.$delivery->tracking_id.'.',
            data: array_merge(
                $this->deliveryPayload($delivery),
                [
                    'payment_id' => $payment->id,
                    'reference' => $payment->reference,
                    'action' => 'open_manual_payment',
                ],
            ),
            channels: ['in_app', 'sms'],
        );
    }

    public function deliveryAssigned(Delivery $delivery): void
    {
        $rider = $delivery->rider;
        $distributor = User::find($delivery->distributor_id);

        if ($rider !== null) {
            $this->notify(
                user: $rider,
                type: NotificationType::DeliveryAssigned,
                title: 'New task: '.$delivery->tracking_id,
                body: 'A delivery has been assigned to you. '
                .'Accept it from the Tasks tab.',
                data: $this->deliveryPayload($delivery),
            );
        }

        if ($distributor !== null) {
            $this->notify(
                user: $distributor,
                type: NotificationType::DeliveryAssigned,
                title: 'Rider assigned',
                body: 'A rider has been assigned to '.$delivery->tracking_id
                .' and is on the way to pick it up.',
                data: $this->deliveryPayload($delivery),
            );
        }
    }

    public function deliveryAccepted(Delivery $delivery): void
    {
        $this->notifyDistributor(
            $delivery,
            NotificationType::DeliveryAccepted,
            'Your rider accepted the delivery',
            'The rider for '.$delivery->tracking_id.' has accepted and will '
            .'pick up the package shortly.',
        );
    }

    public function deliveryPickedUp(Delivery $delivery): void
    {
        $this->notifyDistributor(
            $delivery,
            NotificationType::DeliveryPickedUp,
            'Package picked up',
            'Your package '.$delivery->tracking_id.' has been picked up '
            .'and is en route.',
        );
    }

    public function deliveryDispatched(Delivery $delivery): void
    {
        $this->notifyDistributor(
            $delivery,
            NotificationType::DeliveryDispatched,
            'Package dispatched',
            'Your regional package '.$delivery->tracking_id
            .' has been dispatched on the bus / cargo carrier.',
        );

        $this->notifyRegionalRecipient($delivery, false);
    }

    public function deliveryDelivered(Delivery $delivery): void
    {
        $this->notifyDistributor(
            $delivery,
            NotificationType::DeliveryDelivered,
            'Package delivered',
            'Your package '.$delivery->tracking_id.' has been delivered. '
            .'Thank you for using ODS.',
        );

        $this->notifyRegionalRecipient($delivery, true);
    }

    public function packageReady(Delivery $delivery): void
    {
        if ($delivery->pickup_branch_id === null) {
            return;
        }

        $receivers = User::query()
            ->where('branch_id', $delivery->pickup_branch_id)
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('role', fn ($q) => $q->where('name', Role::RECEIVER))
            ->get();

        $this->notifyMany(
            users: $receivers,
            type: NotificationType::PackageReady,
            title: 'Package ready for handover',
            body: $delivery->tracking_id.' is at the branch and waiting '
            .'for a rider to pick it up.',
            data: $this->deliveryPayload($delivery),
        );
    }

    public function codeIssued(
        Delivery $delivery,
        string $rawDistributorCode,
        string $rawRecipientCode,
    ): void {
        $distributor = User::find($delivery->distributor_id);
        if ($distributor === null) {
            return;
        }

        $smsBody = sprintf(
            'ODS codes for %s: DISTRIBUTOR=%s RECIPIENT=%s. Share the '
            .'DISTRIBUTOR code at pickup and the RECIPIENT code at '
            .'delivery handover.',
            $delivery->tracking_id,
            $rawDistributorCode,
            $rawRecipientCode,
        );

        $this->notify(
            user: $distributor,
            type: NotificationType::CodeIssued,
            title: 'Verification codes issued',
            body: 'Codes for '.$delivery->tracking_id.' have been '
            .'generated. Check your SMS for the codes, or open the '
            .'delivery detail to view them.',
            data: array_merge($this->deliveryPayload($delivery), [
                'sms_message' => $smsBody,
            ]),
            channels: ['in_app', 'sms'],
        );
    }

    public function disputeOpened(Dispute $dispute): void
    {
        $owners = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('role', fn ($q) => $q->where('name', Role::OWNER))
            ->get();

        if ($owners->isEmpty()) {
            return;
        }

        $openerName = $dispute->openedBy?->name ?? 'a user';

        $this->notifyMany(
            users: $owners,
            type: NotificationType::DisputeOpened,
            title: 'New dispute: '.$dispute->subject,
            body: 'A '.$dispute->priority.'-priority '
            .$dispute->category.' dispute was opened by '
            .$openerName.'.',
            data: $this->disputePayload($dispute),
            channels: ['in_app', 'sms'],
        );
    }

    public function disputeResolved(Dispute $dispute): void
    {
        $statusLabel = $dispute->status === Dispute::STATUS_RESOLVED
            ? 'resolved'
            : 'closed';

        $targets = [];
        $opener = User::find($dispute->opened_by);
        if ($opener !== null) {
            $targets[] = $opener;
        }

        // If the dispute named a specific user, they should know too.
        if ($dispute->against_user_id !== null) {
            $against = User::find($dispute->against_user_id);
            if ($against !== null) {
                $targets[] = $against;
            }
        }

        if (empty($targets)) {
            return;
        }

        $this->notifyMany(
            users: $targets,
            type: NotificationType::DisputeResolved,
            title: 'Dispute '.$statusLabel,
            body: 'Your '.$dispute->category.' dispute "'
            .$dispute->subject.'" was '.$statusLabel.'.',
            data: $this->disputePayload($dispute),
            channels: ['in_app', 'sms'],
        );
    }

    // ═════════════════════════════════════════════════════════
    // Internals
    // ═════════════════════════════════════════════════════════

    private function notifyDistributor(
        Delivery $delivery,
        NotificationType $type,
        string $title,
        ?string $body,
    ): void {
        $distributor = User::find($delivery->distributor_id);
        if ($distributor === null) {
            return;
        }

        $this->notify(
            user: $distributor,
            type: $type,
            title: $title,
            body: $body,
            data: $this->deliveryPayload($delivery),
        );
    }

    private function notifyRegionalRecipient(Delivery $delivery, bool $arrived): void
    {
        if (!$delivery->isRegional() || empty($delivery->recipient_phone)) {
            return;
        }

        $distributor = User::find($delivery->distributor_id);
        if ($distributor === null) {
            return;
        }

        $ticket = $delivery->dispatcherTicket;
        $busName = $ticket?->provider_name ?: 'the bus carrier';
        $amount = (float) ($ticket?->transport_fare ?? $delivery->bus_cargo_fare ?? 0);
        $amountLabel = ($ticket?->currency ?? $delivery->currency ?? 'TZS')
            .' '.number_format($amount, 0);
        $recipientName = $delivery->recipient_name ?: 'Recipient';
        $message = $arrived
            ? "Hello {$recipientName}, package {$delivery->tracking_id} has arrived at its destination. Please contact the sender or ODS to collect it."
            : "Hello {$recipientName}, package {$delivery->tracking_id} has been sent via {$busName}. Amount paid: {$amountLabel}.";

        $this->notify(
            user: $distributor,
            type: $arrived
                ? NotificationType::RecipientDeliveryArrived
                : NotificationType::RecipientDeliveryDispatched,
            title: $arrived ? 'Recipient arrival SMS' : 'Recipient dispatch SMS',
            body: $message,
            data: array_merge($this->deliveryPayload($delivery), [
                'sms_message' => $message,
                'sms_destination' => $delivery->recipient_phone,
            ]),
            channels: ['sms'],
        );
    }

    private function hasRecent(int $userId, NotificationType $type, ?int $deliveryId): bool
    {
        if ($deliveryId === null) {
            return false;
        }

        return Notification::query()
            ->where('user_id', $userId)
            ->where('type', $type->value)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->where('data->delivery_id', $deliveryId)
            ->exists();
    }

    private function deliveryPayload(Delivery $delivery): array
    {
        return [
            'delivery_id' => $delivery->id,
            'tracking_id' => $delivery->tracking_id,
            'service_type' => $delivery->service_type,
            'action' => 'open_delivery',
        ];
    }

    private function disputePayload(Dispute $dispute): array
    {
        return [
            'dispute_id' => $dispute->id,
            'delivery_id' => $dispute->delivery_id,
            'tracking_id' => $dispute->delivery?->tracking_id,
            'category' => $dispute->category,
            'status' => $dispute->status,
            'action' => 'open_dispute',
        ];
    }
}
