<?php

namespace App\Services\Sms;

use RuntimeException;
use Throwable;

/**
 * Every Beem SMS failure surfaces as this exception (Phase 13.2).
 *
 * Context fields let the dispatcher make channel-specific decisions
 * (e.g. "insufficient balance" is an ops alert; "invalid phone" is a
 * per-notification terminal failure) without parsing message strings.
 */
class BeemSmsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $beemCode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?array $responseBody = null,
        public readonly ?int $logId = null,
        public readonly ?string $endpoint = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isAuthFailure(): bool
    {
        return $this->beemCode === 120 || $this->httpStatus === 401;
    }

    public function isInsufficientBalance(): bool
    {
        return $this->beemCode === 102;
    }

    public function isInvalidSender(): bool
    {
        return $this->beemCode === 111;
    }

    public function isInvalidRecipient(): bool
    {
        return $this->beemCode === 101 || $this->beemCode === 115;
    }

    public function isServerFailure(): bool
    {
        return $this->beemCode === 103 || ($this->httpStatus ?? 0) >= 500;
    }
}