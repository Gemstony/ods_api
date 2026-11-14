<?php

namespace App\Services\Verification;

use App\Models\Delivery;
use App\Models\Setting;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Verification-code lifecycle (Phase 12.1).
 *
 * Distributes 6-digit codes for two independent gates:
 *   - DISTRIBUTOR_CODE — Receiver verifies at package release / rider
 *                        verifies at pickup. Gated by
 *                        `security.verification_required_pickup`.
 *   - RECIPIENT_CODE   — Rider verifies at handover to recipient.
 *                        Gated by `security.verification_required_delivery`.
 *
 * Codes are stored hashed (SHA-256). The raw value is returned exactly
 * once at issue-time — it must be delivered via SMS (Phase 13) or other
 * channel; it is never recoverable after that.
 *
 * Lockout is PER-CODE, not per-delivery: exhausting attempts on
 * RECIPIENT_CODE does not affect the DISTRIBUTOR_CODE for the same
 * delivery. Regenerate via `issueForDelivery()` to unblock.
 */
class VerificationCodeService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    // ═════════════════════════════════════════════════════════
    // Issue
    // ═════════════════════════════════════════════════════════

    /**
     * Create a fresh code for (delivery, codeType). Revokes any prior
     * unverified code for the same tuple so exactly one is live.
     *
     * Returns [model, rawCode] — the raw code must be consumed
     * immediately (SMS/voice/etc.); it is not retrievable after this call.
     *
     * @return array{0: VerificationCode, 1: string}
     */
    public function issueForDelivery(
        Delivery $delivery,
        string $codeType,
        User $issuedTo,
        ?int $ttlHours = null,
    ): array {
        $this->assertValidCodeType($codeType);
        $this->assertDeliveryNotTerminal($delivery);

        $effectiveTtl = $ttlHours ?? $this->ttlHours();

        /** @var array{0: VerificationCode, 1: string} $result */
        $result = DB::transaction(function () use (
            $delivery,
            $codeType,
            $issuedTo,
            $effectiveTtl,
        ) {
            // Revoke any outstanding unverified code for this (delivery,
            // codeType) so only one is live at a time.
            VerificationCode::where('delivery_id', $delivery->id)
                ->where('code_type', $codeType)
                ->whereNull('verified_at')
                ->update(['expires_at' => now()]);

            return VerificationCode::issueFor(
                $delivery,
                $codeType,
                $issuedTo,
                $effectiveTtl,
            );
        });

        [$model, $rawCode] = $result;

        $this->auditLogger->log(
            auth()->user(),
            action: 'verification.issued',
            description: sprintf(
                'Issued %s code for delivery %s.',
                $codeType,
                $delivery->tracking_id,
            ),
            subject: $model,
            changes: [
                'delivery_id'   => $delivery->id,
                'code_type'     => $codeType,
                'issued_to'     => $issuedTo->id,
                'ttl_hours'     => $effectiveTtl,
                'expires_at'    => $model->expires_at?->toIso8601String(),
            ],
        );

        return [$model, $rawCode];
    }

    // ═════════════════════════════════════════════════════════
    // Readiness (pre-check)
    // ═════════════════════════════════════════════════════════

    public function readinessFor(Delivery $delivery, string $codeType): VerificationReadiness
    {
        $code = $this->latestCodeFor($delivery, $codeType);

        if ($code === null) {
            return VerificationReadiness::NoCode;
        }
        if ($code->verified_at !== null) {
            return VerificationReadiness::AlreadyVerified;
        }
        if ($code->isExpired()) {
            return VerificationReadiness::Expired;
        }
        if ($code->attempt_count >= $this->maxAttempts()) {
            return VerificationReadiness::LockedOut;
        }
        return VerificationReadiness::Ready;
    }

    // ═════════════════════════════════════════════════════════
    // Verify
    // ═════════════════════════════════════════════════════════

    /**
     * Verify a raw code against the delivery's most recent code for
     * `$codeType`. Never throws — all failure modes are returned as a
     * distinct `VerificationOutcome`.
     *
     * On success: marks `verified_at`, `verified_by_user_id`, writes an
     * audit entry, returns `Success` (or `AlreadyVerified` if previously
     * verified — idempotent).
     *
     * On failure: increments `attempt_count`, audits the rejection, and
     * returns the specific outcome. Lockout is permanent for THIS code
     * (caller must regenerate).
     */
    public function verify(
        Delivery $delivery,
        string $codeType,
        string $rawCode,
        User $verifier,
    ): VerificationResult {
        $this->assertValidCodeType($codeType);

        /** @var array{0: VerificationResult} $holder */
        $holder = DB::transaction(function () use (
            $delivery,
            $codeType,
            $rawCode,
            $verifier,
        ) {
            /** @var VerificationCode|null $code */
            $code = VerificationCode::where('delivery_id', $delivery->id)
                ->where('code_type', $codeType)
                ->orderByDesc('issued_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($code === null) {
                return ['result' => new VerificationResult(
                    VerificationOutcome::NoCode,
                    null,
                    null,
                )];
            }

            // Already verified — idempotent success.
            if ($code->verified_at !== null) {
                return ['result' => new VerificationResult(
                    VerificationOutcome::AlreadyVerified,
                    $code,
                    null,
                )];
            }

            // Expired.
            if ($code->isExpired()) {
                return ['result' => new VerificationResult(
                    VerificationOutcome::Expired,
                    $code,
                    null,
                )];
            }

            // Locked out.
            $maxAttempts = $this->maxAttempts();
            if ($code->attempt_count >= $maxAttempts) {
                return ['result' => new VerificationResult(
                    VerificationOutcome::LockedOut,
                    $code,
                    0,
                )];
            }

            // Attempt the match.
            $code->attempt_count = $code->attempt_count + 1;

            if ($code->hashMatches($rawCode)) {
                $code->verified_at = now();
                $code->verified_by_user_id = $verifier->id;
                $code->save();

                return ['result' => new VerificationResult(
                    VerificationOutcome::Success,
                    $code,
                    max(0, $maxAttempts - $code->attempt_count),
                )];
            }

            $code->save();
            $remaining = max(0, $maxAttempts - $code->attempt_count);

            return ['result' => new VerificationResult(
                VerificationOutcome::Invalid,
                $code,
                $remaining,
            )];
        });

        /** @var VerificationResult $result */
        $result = $holder['result'];

        // Audit outside the transaction so a logging failure can't roll
        // back the code-state change.
        $this->auditVerification($result, $delivery, $codeType, $verifier);

        return $result;
    }

    // ═════════════════════════════════════════════════════════
    // Revoke
    // ═════════════════════════════════════════════════════════

    /**
     * Expire all outstanding (unverified) codes for a delivery — used
     * on cancellation or terminal-state cleanup. Verified codes are
     * left untouched (their `verified_at` is historical fact).
     *
     * @return int  Number of codes revoked.
     */
    public function revokeForDelivery(Delivery $delivery, string $reason = 'revoked'): int
    {
        $count = VerificationCode::where('delivery_id', $delivery->id)
            ->whereNull('verified_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->update(['expires_at' => now()]);

        if ($count > 0) {
            $this->auditLogger->log(
                auth()->user(),
                action: 'verification.revoked',
                description: sprintf(
                    'Revoked %d verification code(s) for delivery %s (%s).',
                    $count,
                    $delivery->tracking_id,
                    $reason,
                ),
                subject: $delivery,
                changes: ['count' => $count, 'reason' => $reason],
            );
        }

        return $count;
    }

    // ═════════════════════════════════════════════════════════
    // Settings helpers (readable by controller layer)
    // ═════════════════════════════════════════════════════════

    public function isRequired(string $codeType): bool
    {
        return match ($codeType) {
            VerificationCode::TYPE_DISTRIBUTOR => (bool) Setting::get(
                'security.verification_required_pickup',
                true,
            ),
            VerificationCode::TYPE_RECIPIENT => (bool) Setting::get(
                'security.verification_required_delivery',
                true,
            ),
            default => false,
        };
    }

    public function ttlHours(): int
    {
        return (int) Setting::get('security.verification_code_ttl_hours', 72);
    }

    public function maxAttempts(): int
    {
        return (int) Setting::get('security.max_verification_attempts', 3);
    }

    // ═════════════════════════════════════════════════════════
    // Internals
    // ═════════════════════════════════════════════════════════

    private function latestCodeFor(Delivery $delivery, string $codeType): ?VerificationCode
    {
        return VerificationCode::where('delivery_id', $delivery->id)
            ->where('code_type', $codeType)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->first();
    }

    private function assertValidCodeType(string $codeType): void
    {
        if (! in_array($codeType, [
            VerificationCode::TYPE_DISTRIBUTOR,
            VerificationCode::TYPE_RECIPIENT,
        ], true)) {
            throw VerificationException::invalidCodeType($codeType);
        }
    }

    private function assertDeliveryNotTerminal(Delivery $delivery): void
    {
        if (in_array($delivery->status, [
            Delivery::STATUS_DELIVERED,
            Delivery::STATUS_CANCELLED,
            Delivery::STATUS_FAILED,
            Delivery::STATUS_EXPIRED,
        ], true)) {
            throw VerificationException::deliveryInTerminalState($delivery->status);
        }
    }

    private function auditVerification(
        VerificationResult $result,
        Delivery $delivery,
        string $codeType,
        User $verifier,
    ): void {
        $action = $result->outcome->isSuccess()
            ? 'verification.succeeded'
            : 'verification.failed';

        $this->auditLogger->log(
            $verifier,
            action: $action,
            description: sprintf(
                'Verified %s code for delivery %s: %s.',
                $codeType,
                $delivery->tracking_id,
                $result->outcome->value,
            ),
            subject: $result->code,
            changes: [
                'delivery_id'       => $delivery->id,
                'code_type'         => $codeType,
                'outcome'           => $result->outcome->value,
                'remaining_attempts'=> $result->remainingAttempts,
                // Never log the raw code or any prefix of it.
            ],
        );
    }
}