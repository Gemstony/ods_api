<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ClickPesa callbacks — raw webhook log for idempotency and forensics.
 *
 * Every incoming webhook is stored here BEFORE we act on it, so:
 *   - Replayed events (same `provider_event_id`) are detected and ignored.
 *   - Signature failures are recorded without corrupting `payments`.
 *   - Phase 11's controller reads this table to reconcile.
 *
 * `payment_id` is nullable — the callback may reference a payment that
 * doesn't exist yet (rare but possible during races). The controller can
 * back-fill later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clickpesa_callbacks', function (Blueprint $table) {
            $table->id();

            // ClickPesa's unique event ID — idempotency key.
            $table->string('provider_event_id', 150)->unique();

            $table->string('event_type', 100); // e.g. "payment.success", "payment.failed"

            $table->foreignId('payment_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->boolean('signature_valid')->default(false);

            $table->json('payload');

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();

            $table->timestamps();

            $table->index('event_type');
            $table->index('processed_at');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clickpesa_callbacks');
    }
};