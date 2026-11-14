<?php

namespace App\Services\Verification;

use App\Models\Delivery;
use App\Models\Setting;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Verification-code lifecycle (Phase 12.1/12.2).
 *
 * Two independent gates:
 *   DISTRIBUTOR_CODE — proves the package/handover is legitimate
 *   RECIPIENT_CODE   — proves the recipient is legitimate
 *
 * **Grace-mode arming (12.2):** `isRequired()` returns true only when
 * BOTH (a) the setting is enabled AND (b) a code actually exists for the
 * delivery. This lets existing deliveries transition without codes
 * (backward compatible with pre-12.2 tests and dev data), while
 * Distributors explicitly arm the gate by calling
 * `POST /distributor/deliveries/{id}/verification/issue`.
 */
class VerificationCodeService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    // ═════════════════════════════════════════════════════════
    // Issue
    // ═════════════════════════════════════════════════════════

    /**
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
                'delivery_id' => $delivery->id,
                'code_type'   => $codeType,
                'issued_to'   => $issuedTo->id,
                'ttl_hours'   => $effectiveTtl,
                'expires_at'  => $model->expires_at?->toIso8601String(),
            ],
        );

        return [$model, $rawCode];
    }

    // ═════════════════════════════════════════════════════════
    // Readiness
    // ═════════════════════════════════════════════════════════

    public function readinessFor(Delivery $delivery, string $codeType): VerificationReadiness
    {
        $code = $this->latestCodeFor($delivery, $codeType);

        if ($code === null) return VerificationReadiness::NoCode;
        if ($code->verified_at !== null) return VerificationReadiness::AlreadyVerified;
        if ($code->isExpired()) return VerificationReadiness::Expired;
        if ($code->attempt_count >= $this->maxAttempts()) {
            return VerificationReadiness::LockedOut;
        }
        return VerificationReadiness::Ready;
    }

    // ═════════════════════════════════════════════════════════
    // Verify
    // ═════════════════════════════════════════════════════════

    public function verify(
        Delivery $delivery,
        string $codeType,
        string $rawCode,
        User $verifier,
    ): VerificationResult {
        $this->assertValidCodeType($codeType);

        /** @var array{result: VerificationResult} $holder */
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

            if ($code->verified_at !== null) {
                return ['result' => new VerificationResult(
                    VerificationOutcome::AlreadyVerified,
                    $code,
                    null,
                )];
            }

            if ($code->isExpired()) {
                return ['result' => new VerificationResult(
                    VerificationOutcome::Expired,
                    $code,
                    null,
                )];
            }

            $maxAttempts = $this->maxAttempts();
            if ($code->attempt_count >= $maxAttempts) {
                return ['result' => new VerificationResult(
                    VerificationOutcome::LockedOut,
                    $code,
                    0,
                )];
            }

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

        $this->auditVerification($result, $delivery, $codeType, $verifier);

        return $result;
    }

    // ═════════════════════════════════════════════════════════
    // Revoke
    // ═════════════════════════════════════════════════════════

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
    // Settings helpers
    // ═════════════════════════════════════════════════════════

    /**
     * Whether verification is required for this code type on this delivery.
     *
     * Grace-mode arming (12.2):
     *   - Setting off          → never required
     *   - Setting on, no code  → not required (grace)
     *   - Setting on, code exists → required
     *
     * The optional `$delivery` parameter preserves backward compatibility
     * with Phase 12.1 tests that call `isRequired(TYPE)` alone — those
     * only check the setting value, without the grace-mode check.
     */
    public function isRequired(string $codeType, ?Delivery $delivery = null): bool
    {
        $settingEnabled = match ($codeType) {
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

        if (! $settingEnabled) {
            return false;
        }

        // No delivery provided → return the raw setting value (12.1 compat).
        if ($delivery === null) {
            return true;
        }

        // Grace mode: gate arms only once a code exists for this delivery.
        return VerificationCode::where('delivery_id', $delivery->id)
            ->where('code_type', $codeType)
            ->exists();
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
                'delivery_id'        => $delivery->id,
                'code_type'          => $codeType,
                'outcome'            => $result->outcome->value,
                'remaining_attempts' => $result->remainingAttempts,
            ],
        );
    }
}