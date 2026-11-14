<?php

namespace App\Services\Verification;

use App\Models\VerificationCode;

/**
 * Returned by `VerificationCodeService::verify()` (Phase 12.1).
 */
class VerificationResult
{
    public function __construct(
        public readonly VerificationOutcome $outcome,
        public readonly ?VerificationCode $code,
        public readonly ?int $remainingAttempts,
    ) {}

    public function isSuccess(): bool
    {
        return $this->outcome->isSuccess();
    }

    /**
     * Serialize for controller responses. Exposes the code's metadata
     * (never the hash, never the raw value).
     */
    public function toArray(): array
    {
        return [
            'success'            => $this->isSuccess(),
            'outcome'            => $this->outcome->value,
            'message'            => $this->outcome->message(),
            'remaining_attempts' => $this->remainingAttempts,
            'code'               => $this->code === null ? null : [
                'id'            => $this->code->id,
                'delivery_id'   => $this->code->delivery_id,
                'code_type'     => $this->code->code_type,
                'issued_at'     => $this->code->issued_at?->toIso8601String(),
                'expires_at'    => $this->code->expires_at?->toIso8601String(),
                'verified_at'   => $this->code->verified_at?->toIso8601String(),
                'attempt_count' => $this->code->attempt_count,
            ],
        ];
    }
}