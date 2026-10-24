<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Earnings — the compensation ledger for riders and dispatchers.
 *
 * One row per compensation event:
 *   rider_baseline        80% of applicable delivery fee (per delivery)
 *   rider_bonus           +10% for additional-order bonuses (per delivery)
 *   dispatcher_flat       fixed amount per dispatched package (per delivery)
 *   dispatcher_shift      shift allowance (NOT tied to a delivery — delivery_id null)
 *   dispatcher_percent    approved % of a defined fee (per delivery)
 *   adjustment            manual correction (positive or negative)
 *
 * The ODS document: "Never treat regional transport fares as Dispatcher
 * income." This table has no column for transport fares. The bus/cargo
 * liability lives exclusively in `dispatcher_tickets.transport_fare`.
 *
 * Negative `amount` represents clawbacks/adjustments. Ledger totals are
 * always SUM(amount) by status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earnings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Nullable because dispatcher_shift is not tied to a delivery.
            $table->foreignId('delivery_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('type', [
                'rider_baseline',
                'rider_bonus',
                'dispatcher_flat',
                'dispatcher_shift',
                'dispatcher_percent',
                'adjustment',
            ]);

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('TZS');

            $table->enum('status', [
                'pending',
                'approved',
                'paid',
                'cancelled',
            ])->default('pending');

            $table->timestamp('earned_at');

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('payout_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['type', 'status']);
            $table->index(['delivery_id']);
            $table->index(['payout_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('earnings');
    }
};