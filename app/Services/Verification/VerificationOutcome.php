<?php

namespace App\Services\Verification;

/**
 * The result of one `verify()` attempt (Phase 12.1).
 *
 * `Success` and `AlreadyVerified` are both "positive" for the caller —
 * either the code was just proven, or it was proven before and the
 * caller is safely re-checking. Every other case is a failure with a
 * distinct, actionable reason.
 */
enum VerificationOutcome: string
{
    case Success         = 'success';
    case AlreadyVerified = 'already_verified';
    case NoCode          = 'no_code';
    case Expired         = 'expired';
    case LockedOut       = 'locked_out';
    case Invalid         = 'invalid';
    case WrongDelivery   = 'wrong_delivery';

    public function isSuccess(): bool
    {
        return $this === self::Success || $this === self::AlreadyVerified;
    }

    /**
     * User-facing message. Controllers can return this directly.
     */
    public function message(): string
    {
        return match ($this) {
            self::Success         => 'Code verified.',
            self::AlreadyVerified => 'Code already verified.',
            self::NoCode          => 'No verification code exists for this delivery.',
            self::Expired         => 'This verification code has expired. Request a new one.',
            self::LockedOut       => 'Too many incorrect attempts. Request a new code.',
            self::Invalid         => 'Incorrect code.',
            self::WrongDelivery   => 'This code belongs to a different delivery.',
        };
    }
}