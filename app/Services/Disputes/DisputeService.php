<?php

namespace App\Services\Disputes;

use App\Models\Delivery;
use App\Models\Dispute;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Dispute lifecycle (Phase 13.4).
 *
 * Status machine (from the `disputes` migration):
 *
 *   open ──────► in_review ──────► resolved ──── terminal
 *    │               │                ▲
 *    │               │                │
 *    ├──► resolved ──┘                │
 *    │               │                │
 *    ├──► closed ────┴─────────────► closed ──── terminal
 *    │               │
 *    └──► escalated ─┴──► resolved / closed / in_review
 *
 * Any active → any non-terminal status is allowed; anything → `resolved`
 * or `closed` from a non-terminal is allowed; nothing exits a terminal
 * state (`resolved`, `closed`). Re-opening creates a new dispute.
 *
 * Notifications fire after the DB write commits — never inside the
 * transaction. Best-effort: a notification failure doesn't roll back.
 */
class DisputeService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notifications,
    ) {}

    // ═════════════════════════════════════════════════════════
    // Open
    // ═════════════════════════════════════════════════════════

    public function open(
        User $opener,
        int $deliveryId,
        string $category,
        string $subject,
        string $description,
        string $priority = Dispute::PRIORITY_NORMAL,
        ?int $againstUserId = null,
        array $attachments = [],
    ): Dispute {
        if (! in_array($category, [
            Dispute::CATEGORY_DELIVERY,
            Dispute::CATEGORY_PAYMENT,
            Dispute::CATEGORY_PACKAGE,
            Dispute::CATEGORY_COMPENSATION,
            Dispute::CATEGORY_OTHER,
        ], true)) {
            throw DisputeException::invalidCategory($category);
        }

        if (! in_array($priority, [
            Dispute::PRIORITY_LOW,
            Dispute::PRIORITY_NORMAL,
            Dispute::PRIORITY_HIGH,
            Dispute::PRIORITY_URGENT,
        ], true)) {
            throw DisputeException::invalidPriority($priority);
        }

        $delivery = Delivery::find($deliveryId);
        if ($delivery === null) {
            throw new DisputeException("Delivery #{$deliveryId} not found.", 404);
        }

        // Role-scoped access — enforced in the service so every controller
        // gets the same rule without duplicating logic.
        $this->assertCanAccessDelivery($opener, $delivery);

        $dispute = Dispute::create([
            'delivery_id'     => $delivery->id,
            'opened_by'       => $opener->id,
            'against_user_id' => $againstUserId,
            'category'        => $category,
            'subject'         => $subject,
            'description'     => $description,
            'priority'        => $priority,
            'status'          => Dispute::STATUS_OPEN,
            'attachments'     => empty($attachments) ? null : $attachments,
        ]);

        $this->auditLogger->log(
            $opener,
            action: 'dispute.opened',
            description: "Opened {$category} dispute about delivery {$delivery->tracking_id}.",
            subject: $dispute,
            changes: [
                'delivery_id'     => $delivery->id,
                'category'        => $category,
                'priority'        => $priority,
                'subject'         => $subject,
                'against_user_id' => $againstUserId,
            ],
        );

        try {
            $this->notifications->disputeOpened($dispute->fresh());
        } catch (\Throwable $e) {
            Log::error('Notification write after dispute.opened failed', [
                'dispute_id' => $dispute->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return $dispute->fresh();
    }

    // ═════════════════════════════════════════════════════════
    // Owner actions
    // ═════════════════════════════════════════════════════════

    public function startReview(User $owner, Dispute $dispute): Dispute
    {
        if (! $dispute->isActive()) {
            throw DisputeException::terminalState($dispute->status, Dispute::STATUS_IN_REVIEW);
        }
        if ($dispute->status === Dispute::STATUS_IN_REVIEW) {
            return $dispute; // no-op
        }

        return $this->transition(
            owner: $owner,
            dispute: $dispute,
            targetStatus: Dispute::STATUS_IN_REVIEW,
            auditAction: 'dispute.review_started',
            auditDescription: "Started review of dispute #{$dispute->id}.",
        );
    }

    public function resolve(User $owner, Dispute $dispute, string $resolution): Dispute
    {
        if (! $dispute->isActive()) {
            throw DisputeException::terminalState($dispute->status, Dispute::STATUS_RESOLVED);
        }

        $updated = $this->transition(
            owner: $owner,
            dispute: $dispute,
            targetStatus: Dispute::STATUS_RESOLVED,
            auditAction: 'dispute.resolved',
            auditDescription: "Resolved dispute #{$dispute->id}.",
            extraFields: [
                'resolution'  => $resolution,
                'resolved_by' => $owner->id,
                'resolved_at' => now(),
            ],
        );

        $this->fireResolutionNotification($updated);
        return $updated;
    }

    public function close(User $owner, Dispute $dispute, ?string $reason = null): Dispute
    {
        if (! $dispute->isActive()) {
            throw DisputeException::terminalState($dispute->status, Dispute::STATUS_CLOSED);
        }

        $updated = $this->transition(
            owner: $owner,
            dispute: $dispute,
            targetStatus: Dispute::STATUS_CLOSED,
            auditAction: 'dispute.closed',
            auditDescription: "Closed dispute #{$dispute->id}.",
            extraFields: [
                'resolution'  => $reason ?? 'Closed by Owner.',
                'resolved_by' => $owner->id,
                'resolved_at' => now(),
            ],
        );

        $this->fireResolutionNotification($updated);
        return $updated;
    }

    public function escalate(User $owner, Dispute $dispute): Dispute
    {
        if (! $dispute->isActive()) {
            throw DisputeException::terminalState($dispute->status, Dispute::STATUS_ESCALATED);
        }
        if ($dispute->status === Dispute::STATUS_ESCALATED) {
            return $dispute; // no-op
        }

        return $this->transition(
            owner: $owner,
            dispute: $dispute,
            targetStatus: Dispute::STATUS_ESCALATED,
            auditAction: 'dispute.escalated',
            auditDescription: "Escalated dispute #{$dispute->id}.",
        );
    }

    // ═════════════════════════════════════════════════════════
    // Internals
    // ═════════════════════════════════════════════════════════

    private function transition(
        User $owner,
        Dispute $dispute,
        string $targetStatus,
        string $auditAction,
        string $auditDescription,
        array $extraFields = [],
    ): Dispute {
        $fromStatus = $dispute->status;

        $dispute->update(array_merge([
            'status' => $targetStatus,
        ], $extraFields));

        $this->auditLogger->log(
            $owner,
            action: $auditAction,
            description: $auditDescription,
            subject: $dispute,
            changes: [
                'status' => ['from' => $fromStatus, 'to' => $targetStatus],
                'extra'  => array_keys($extraFields),
            ],
        );

        return $dispute->fresh();
    }

    private function fireResolutionNotification(Dispute $dispute): void
    {
        try {
            $this->notifications->disputeResolved($dispute);
        } catch (\Throwable $e) {
            Log::error('Notification write after dispute resolution failed', [
                'dispute_id' => $dispute->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function assertCanAccessDelivery(User $user, Delivery $delivery): void
    {
        // Owner can open disputes about any delivery (usually as a proxy).
        if ($user->hasRole(\App\Models\Role::OWNER)) {
            return;
        }

        // Distributor can only open about their own deliveries.
        if ($user->hasRole(\App\Models\Role::DISTRIBUTOR)) {
            if ((int) $delivery->distributor_id !== (int) $user->id) {
                throw DisputeException::deliveryNotAccessible($delivery->id, 'distributor');
            }
            return;
        }

        // Manager can only open about deliveries at their own branch.
        if ($user->hasRole(\App\Models\Role::MANAGER)) {
            if ($user->branch_id === null
                || (int) $delivery->pickup_branch_id !== (int) $user->branch_id) {
                throw DisputeException::deliveryNotAccessible($delivery->id, 'manager');
            }
            return;
        }

        // Rider can only open about deliveries currently or formerly assigned to them.
        if ($user->hasRole(\App\Models\Role::RIDER)) {
            if ((int) $delivery->rider_id !== (int) $user->id) {
                throw DisputeException::deliveryNotAccessible($delivery->id, 'rider');
            }
            return;
        }

        // Receivers and anyone else: 403.
        throw DisputeException::deliveryNotAccessible($delivery->id, 'user');
    }
}