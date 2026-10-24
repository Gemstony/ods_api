<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-distributor reward progress.
 *
 * State is derived from two counters:
 *   monthly_count — resets each calendar month (tier1: 20 threshold)
 *   rolling_count — recomputed each delivery (tier2: 50 threshold in N days)
 *
 * Redemption: subtract threshold from the relevant counter. If the
 * remaining count still meets the threshold, the tier immediately
 * re-flips to "available" for the next redemption.
 */
class Reward extends Model
{
    use HasFactory;

    protected $fillable = [
        'distributor_id',
        'current_month',
        'monthly_count',
        'tier1_available',
        'tier1_reached_at',
        'tier1_expires_at',
        'rolling_count',
        'rolling_window_start',
        'tier2_available',
        'tier2_reached_at',
        'tier2_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'monthly_count'       => 'integer',
            'rolling_count'       => 'integer',
            'tier1_available'     => 'boolean',
            'tier2_available'     => 'boolean',
            'tier1_reached_at'    => 'datetime',
            'tier1_expires_at'    => 'datetime',
            'tier2_reached_at'    => 'datetime',
            'tier2_expires_at'    => 'datetime',
            'rolling_window_start'=> 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    // ─── Accessors ────────────────────────────────────────────
    public function isTier1Usable(): bool
    {
        return $this->tier1_available
            && ($this->tier1_expires_at === null || ! $this->tier1_expires_at->isPast());
    }

    public function isTier2Usable(): bool
    {
        return $this->tier2_available
            && ($this->tier2_expires_at === null || ! $this->tier2_expires_at->isPast());
    }

    // ─── Static entry point ───────────────────────────────────
    /**
     * Get (or lazily create) the reward record for a distributor.
     */
    public static function for(User $distributor): self
    {
        return static::firstOrCreate(
            ['distributor_id' => $distributor->id],
            [
                'current_month'        => now()->format('Y-m'),
                'monthly_count'        => 0,
                'rolling_count'        => 0,
                'rolling_window_start' => now(),
                'tier1_available'      => false,
                'tier2_available'      => false,
            ],
        );
    }

    // ─── State transitions (called from Phase 13's RewardService) ─
    public function recordSuccessfulDelivery(
        int $tier1Threshold,
        int $tier2Threshold,
        int $rollingWindowDays,
        int $expiryDays = 30,
    ): void {
        $now = now();

        // Monthly rollover
        $currentMonth = $now->format('Y-m');
        if ($this->current_month !== $currentMonth) {
            $this->current_month = $currentMonth;
            $this->monthly_count = 0;
        }

        $this->monthly_count++;

        // Recompute rolling count
        $this->rolling_count = Delivery::query()
            ->where('distributor_id', $this->distributor_id)
            ->where('status', Delivery::STATUS_DELIVERED)
            ->where('delivered_at', '>=', $now->copy()->subDays($rollingWindowDays))
            ->count();

        // Unlock tier 1
        if (! $this->tier1_available && $this->monthly_count >= $tier1Threshold) {
            $this->tier1_available  = true;
            $this->tier1_reached_at = $now;
            $this->tier1_expires_at = $now->copy()->addDays($expiryDays);
        }

        // Unlock tier 2
        if (! $this->tier2_available && $this->rolling_count >= $tier2Threshold) {
            $this->tier2_available  = true;
            $this->tier2_reached_at = $now;
            $this->tier2_expires_at = $now->copy()->addDays($expiryDays);
        }

        $this->save();
    }

    /**
     * Mark a tier-1 reward as redeemed. Subtracts the threshold from the
     * monthly counter; if the count still meets the threshold, re-flips
     * `tier1_available` immediately.
     */
    public function redeemTier1(int $tier1Threshold, int $expiryDays = 30): bool
    {
        if (! $this->isTier1Usable()) {
            return false;
        }

        $this->monthly_count    = max(0, $this->monthly_count - $tier1Threshold);
        $this->tier1_available  = false;
        $this->tier1_reached_at = null;
        $this->tier1_expires_at = null;

        if ($this->monthly_count >= $tier1Threshold) {
            $this->tier1_available  = true;
            $this->tier1_reached_at = now();
            $this->tier1_expires_at = now()->addDays($expiryDays);
        }

        $this->save();
        return true;
    }

    public function redeemTier2(int $tier2Threshold, int $expiryDays = 30): bool
    {
        if (! $this->isTier2Usable()) {
            return false;
        }

        $this->rolling_count    = max(0, $this->rolling_count - $tier2Threshold);
        $this->tier2_available  = false;
        $this->tier2_reached_at = null;
        $this->tier2_expires_at = null;

        if ($this->rolling_count >= $tier2Threshold) {
            $this->tier2_available  = true;
            $this->tier2_reached_at = now();
            $this->tier2_expires_at = now()->addDays($expiryDays);
        }

        $this->save();
        return true;
    }
}