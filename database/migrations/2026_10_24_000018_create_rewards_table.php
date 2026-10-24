<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rewards — per-distributor progress toward the ODS reward tiers.
 *
 * Per the ODS document:
 *   Tier 1: 20 successful monthly services → 50% discount
 *   Tier 2: 50 successful requests in the configured rolling period → free eligible delivery
 *
 * `tier1_available` and `tier2_available` are the state flags. When a
 * tier is redeemed:
 *   - tier1_available = false
 *   - monthly_count  -= tier1_threshold   (default 20)
 *   - If monthly_count still >= threshold → tier1_available flips back to true
 *     (handles "40 services → 2 discounts in one month")
 *
 * Same pattern for tier2 with `rolling_count`.
 *
 * `current_month` detects monthly rollover; Phase 13's RewardService
 * resets `monthly_count` to 0 when the month changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distributor_id')->unique()->constrained('users')->cascadeOnDelete();

            // ─── Monthly counter (Tier 1) ─────────────────────────
            $table->string('current_month', 7); // 'YYYY-MM'
            $table->unsignedInteger('monthly_count')->default(0);
            $table->boolean('tier1_available')->default(false);
            $table->timestamp('tier1_reached_at')->nullable();
            $table->timestamp('tier1_expires_at')->nullable();

            // ─── Rolling counter (Tier 2) ─────────────────────────
            $table->unsignedInteger('rolling_count')->default(0);
            $table->timestamp('rolling_window_start')->nullable();
            $table->boolean('tier2_available')->default(false);
            $table->timestamp('tier2_reached_at')->nullable();
            $table->timestamp('tier2_expires_at')->nullable();

            $table->timestamps();

            $table->index(['tier1_available', 'tier1_expires_at']);
            $table->index(['tier2_available', 'tier2_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};