<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing configuration — singleton row (id = 1).
 *
 * The Owner manages these values through the admin UI (Phase 5). Each
 * delivery SNAPSHOTS the applicable values at creation time (see the
 * `deliveries` table in 2.3), so historical fee math can always be
 * reproduced even after the Owner changes this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_config', function (Blueprint $table) {
            $table->id();

            // ─── Local delivery (Receive + Send) ──────────────────
            $table->decimal('local_rate_per_km', 10, 2);
            $table->decimal('local_minimum_fee', 12, 2);
            $table->unsignedInteger('local_rounding_increment');

            // ─── Regional delivery (ODS portion) ──────────────────
            // The bus/cargo transport fare is NOT stored here — it's
            // specific to each regional delivery (bus_cargo_fare column on
            // deliveries) and to each dispatcher ticket. This row only
            // configures the ODS-side regional fee.
            $table->decimal('regional_base_fee', 12, 2)->default(0);
            $table->decimal('regional_minimum_fee', 12, 2)->default(0);
            $table->unsignedInteger('regional_rounding_increment')->default(500);

            // ─── Rider compensation ───────────────────────────────
            $table->decimal('rider_baseline_percent', 5, 2)->default(80.00);
            $table->decimal('rider_bonus_percent', 5, 2)->default(10.00);

            // ─── Dispatcher compensation ──────────────────────────
            // The doc gives three models; the Owner selects one via
            // `dispatcher_compensation_model`. The other two fields are
            // ignored when not selected.
            $table->enum('dispatcher_compensation_model',
                ['flat_per_package', 'shift_allowance', 'percent_of_fee']
            )->default('flat_per_package');
            $table->decimal('dispatcher_flat_fee', 12, 2)->default(0);
            $table->decimal('dispatcher_shift_allowance', 12, 2)->default(0);
            $table->decimal('dispatcher_percent', 5, 2)->default(0);

            // ─── Rewards ──────────────────────────────────────────
            $table->unsignedInteger('reward_tier1_threshold')->default(20);   // 50% discount
            $table->decimal('reward_tier1_discount_percent', 5, 2)->default(50.00);
            $table->unsignedInteger('reward_tier2_threshold')->default(50);   // free eligible delivery
            $table->unsignedInteger('reward_rolling_window_days')->default(30);

            // ─── Audit ────────────────────────────────────────────
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_config');
    }
};