<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\ClickPesa\ClickPesaException;
use RuntimeException;

/**
 * Domain-level exception for the payment service.
 *
 * Carries an HTTP status hint so the controller can map to the ODS
 * envelope without parsing message strings:
 *   409 — duplicate pending payment / already paid
 *   422 — invalid stage / amount / delivery not payable
 *   502 — ClickPesa upstream failure
 */
class PaymentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 422,
        public readonly ?array $context = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidStage(string $stage): self
    {
        return new self("Invalid payment stage '{$stage}'.", 422, ['stage' => $stage]);
    }

    public static function deliveryNotPayable(string $status): self
    {
        return new self(
            "Delivery is not payable in its current state ({$status}).",
            422,
            ['delivery_status' => $status],
        );
    }

    public static function invalidAmount(string $stage): self
    {
        return new self(
            "Amount for stage '{$stage}' is zero or invalid.",
            422,
            ['stage' => $stage],
        );
    }

    public static function duplicatePending(Payment $existing): self
    {
        return new self(
            'A payment for this delivery/stage is already pending. '
            . 'Refresh its status instead of initiating again.',
            409,
            ['existing_payment_id' => $existing->id, 'reference' => $existing->reference],
        );
    }

    public static function alreadyPaid(string $stage): self
    {
        return new self(
            "Payment for stage '{$stage}' is already completed.",
            409,
            ['stage' => $stage],
        );
    }

    public static function providerError(ClickPesaException $e): self
    {
        return new self(
            'ClickPesa rejected the request: ' . $e->getMessage(),
            502,
            [
                'http_status' => $e->httpStatus,
                'log_id'      => $e->logId,
                'endpoint'    => $e->endpoint,
            ],
            $e,
        );
    }
}