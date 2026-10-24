<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments — one row per payment attempt (per stage).
 *
 * Local (Receive/Send): one payment row per delivery, stage = 'full'.
 * Regional:             one row per stage — 'ods_fee', 'transport_fare',
 *                       or 'full' if paid in a single shot.
 *
 * The row is mutable only in its `status` field (and the trailing
 * timestamps). Attempts, callbacks, and refunds are captured separately
 * in `payment_transactions` (append-only log).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();

            $table->enum('stage', ['full', 'ods_fee', 'transport_fare'])
                ->default('full');

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('TZS');

            $table->enum('method', ['clickpesa', 'cash', 'manual'])
                ->default('clickpesa');

            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'cancelled',
                'refunded',
                'expired',
            ])->default('pending');

            // Internal reference — unique, human-readable.
            $table->string('reference', 40)->unique();

            // ClickPesa's own reference (populated when initiate succeeds).
            $table->string('provider_reference', 100)->nullable();

            // Who initiated the payment (usually the distributor).
            $table->foreignId('initiated_by')->constrained('users');

            $table->timestamp('initiated_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->string('failure_reason')->nullable();

            // Free-form metadata (e.g., ClickPesa's original USSD response).
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['delivery_id', 'stage']);
            $table->index('status');
            $table->index('provider_reference');
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};