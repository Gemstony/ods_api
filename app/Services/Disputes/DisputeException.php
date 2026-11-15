<?php

namespace App\Services\Disputes;

use RuntimeException;

/**
 * Domain-level exception for the dispute service (Phase 13.4).
 *
 * Carries an HTTP status hint so the controller maps directly:
 *   403 — cross-branch / cross-role access attempt
 *   404 — delivery or dispute not found
 *   409 — state transition rejected (already resolved, etc.)
 *   422 — invalid category / priority / payload
 */
class DisputeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 422,
        public readonly ?array $context = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidCategory(string $category): self
    {
        return new self("Invalid dispute category '{$category}'.", 422, [
            'category' => $category,
        ]);
    }

    public static function invalidPriority(string $priority): self
    {
        return new self("Invalid dispute priority '{$priority}'.", 422, [
            'priority' => $priority,
        ]);
    }

    public static function deliveryNotAccessible(int $deliveryId, string $role): self
    {
        return new self(
            "Delivery #{$deliveryId} is not accessible for this {$role}.",
            403,
            ['delivery_id' => $deliveryId],
        );
    }

    public static function terminalState(string $status, string $targetStatus): self
    {
        return new self(
            "Cannot transition dispute to '{$targetStatus}' — it is already '{$status}'.",
            409,
            ['current_status' => $status, 'target_status' => $targetStatus],
        );
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self(
            "Cannot transition dispute from '{$from}' to '{$to}'.",
            409,
            ['from' => $from, 'to' => $to],
        );
    }
}