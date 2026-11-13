<?php

namespace App\Services\ClickPesa;

/**
 * The five statuses ClickPesa returns for USSD push payments.
 *
 * SUCCESS and SETTLED are both terminal success — the difference is
 * whether funds have hit the merchant's ClickPesa balance yet. Phase
 * 11.2's Payment state machine maps both to `Payment::STATUS_COMPLETED`
 * because the funds are guaranteed.
 */
enum PaymentStatus: string
{
    case PENDING    = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case SUCCESS    = 'SUCCESS';
    case SETTLED    = 'SETTLED';
    case FAILED     = 'FAILED';
    case UNKNOWN    = 'UNKNOWN';

    public static function fromString(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::UNKNOWN;
        }
        return self::tryFrom(strtoupper($value)) ?? self::UNKNOWN;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::SUCCESS, self::SETTLED, self::FAILED], true);
    }

    public function isSuccess(): bool
    {
        return in_array($this, [self::SUCCESS, self::SETTLED], true);
    }

    public function isPending(): bool
    {
        return in_array($this, [self::PENDING, self::PROCESSING], true);
    }
}