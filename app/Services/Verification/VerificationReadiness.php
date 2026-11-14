<?php

namespace App\Services\Verification;

/**
 * Pre-check before `verify()` — used by controllers to decide whether
 * it's worth showing a code-entry UI at all (Phase 12.1).
 *
 * Differs from `VerificationOutcome`: this is asked BEFORE an attempt,
 * so there's no `Invalid` (no wrong code yet); instead there's `Ready`.
 */
enum VerificationReadiness: string
{
    case Ready            = 'ready';
    case NoCode           = 'no_code';
    case Expired          = 'expired';
    case LockedOut        = 'locked_out';
    case AlreadyVerified  = 'already_verified';

    public function canAttempt(): bool
    {
        return $this === self::Ready;
    }

    public function message(): string
    {
        return match ($this) {
            self::Ready            => 'Ready to accept a code.',
            self::NoCode           => 'No verification code exists yet.',
            self::Expired          => 'Verification code has expired.',
            self::LockedOut        => 'Lockout — request a new code.',
            self::AlreadyVerified  => 'Already verified.',
        };
    }
}