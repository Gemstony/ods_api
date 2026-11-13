<?php

namespace App\Services\ClickPesa;

use InvalidArgumentException;

/**
 * Immutable value object for a USSD push request (Phase 11.1).
 *
 * Used by both `previewUssdPush()` and `initiateUssdPush()`. The only
 * difference between preview and initiate at the wire level is the
 * endpoint — the body is identical.
 *
 * Validation follows the ClickPesa docs:
 *   - orderReference: 1–20 chars, alphanumeric + hyphens + underscores.
 *     (Docs say "alphanumeric"; ClickPesa's own UUID examples include
 *      hyphens, so we accept them.)
 *   - phoneNumber: 255XXXXXXXXX (Tanzania MSISDN).
 *   - amount: strictly positive; sent as a string per ClickPesa's contract.
 *
 * The caller is responsible for keeping `orderReference` stable across
 * retries — Phase 11.2 uses `Payment.reference` (e.g. `PAY-20261109-A3F2`),
 * which the `Payment` model already generates.
 */
final class UssdPushRequest
{
    private function __construct(
        public readonly string $orderReference,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $phoneNumber,
        public readonly bool $fetchSenderDetails,
    ) {}

    public static function create(
        string $orderReference,
        float $amount,
        string $phoneNumber,
        string $currency = 'TZS',
        bool $fetchSenderDetails = true,
    ): self {
        if (! preg_match('/^[A-Za-z0-9_-]{1,20}$/', $orderReference)) {
            throw new InvalidArgumentException(
                "orderReference must be 1–20 chars (A-Za-z0-9_-). Got: '{$orderReference}'"
            );
        }
        if (! preg_match('/^255\d{9}$/', $phoneNumber)) {
            throw new InvalidArgumentException(
                "phoneNumber must be in 255XXXXXXXXX format. Got: '{$phoneNumber}'"
            );
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "amount must be > 0. Got: {$amount}"
            );
        }
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException(
                "currency must be a 3-letter ISO code. Got: '{$currency}'"
            );
        }

        return new self(
            orderReference: $orderReference,
            amount: $amount,
            currency: $currency,
            phoneNumber: $phoneNumber,
            fetchSenderDetails: $fetchSenderDetails,
        );
    }

    public function toPayload(): array
    {
        return [
            'amount'             => (string) $this->amount, // string per docs
            'currency'           => $this->currency,
            'orderReference'     => $this->orderReference,
            'phoneNumber'        => $this->phoneNumber,
            'fetchSenderDetails' => $this->fetchSenderDetails,
        ];
    }
}