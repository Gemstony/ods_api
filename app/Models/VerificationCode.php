<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A DISTRIBUTOR_CODE or RECIPIENT_CODE for one delivery.
 *
 * Raw codes are never stored — only SHA-256 hashes. Use the static
 * [issueFor] helper to create a code and get the raw value back once.
 */
class VerificationCode extends Model
{
    use HasFactory;

    public const TYPE_DISTRIBUTOR = 'distributor';
    public const TYPE_RECIPIENT = 'recipient';

    protected $fillable = [
        'delivery_id',
        'code_type',
        'code_hash',
        'issued_to_user_id',
        'issued_at',
        'verified_at',
        'verified_by_user_id',
        'expires_at',
        'attempt_count',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function issuedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_to_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    // ─── State helpers ────────────────────────────────────────
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return !$this->isVerified() && !$this->isExpired();
    }

    public function hashMatches(string $rawCode): bool
    {
        return hash_equals($this->code_hash, hash('sha256', $rawCode));
    }

    // ─── Issue helper ─────────────────────────────────────────
    /**
     * Creates a verification code and returns [model, rawCode].
     *
     * The raw code is 6 digits — short enough to type on a phone,
     * long enough (1,000,000 possibilities) with rate-limiting at the
     * delivery-agent side.
     *
     * @return array{0: self, 1: string}
     */
    public static function issueFor(
        Delivery $delivery,
        string $codeType,
        User $issuedTo,
        ?int $ttlHours = 72,
    ): array {
        $rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $model = static::create([
            'delivery_id' => $delivery->id,
            'code_type' => $codeType,
            'code_hash' => hash('sha256', $rawCode),
            'issued_to_user_id' => $issuedTo->id,
            'issued_at' => now(),
            'expires_at' => $ttlHours !== null ? now()->addHours($ttlHours) : null,
        ]);

        return [$model, $rawCode];
    }
}