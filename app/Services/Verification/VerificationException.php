<?php

namespace App\Services\Verification;

use RuntimeException;

/**
 * Thrown by `issueForDelivery()` when the delivery or the request
 * itself is in a state that can't produce a valid code.
 *
 * `verify()` never throws — it returns `VerificationResult`. The split
 * is intentional: issuing is a synchronous "should work" operation;
 * verification is an expected-failure-path user action.
 */
class VerificationException extends RuntimeException
{
    public static function invalidCodeType(string $codeType): self
    {
        return new self("Invalid code type '{$codeType}'.");
    }

    public static function deliveryInTerminalState(string $status): self
    {
        return new self(
            "Cannot issue a verification code for a delivery in '{$status}' state."
        );
    }

    public static function deliveryNotFound(int $deliveryId): self
    {
        return new self("Delivery #{$deliveryId} not found.");
    }
}