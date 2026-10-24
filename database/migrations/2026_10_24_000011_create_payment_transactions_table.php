<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment transactions — append-only log of every interaction with the
 * payment provider (ClickPesa) or manual action.
 *
 * One row per event:
 *   - initiate: request sent to ClickPesa
 *   - callback: webhook received from ClickPesa
 *   - verify:   manual or scheduled status poll
 *   - refund:   refund issued
 *
 * Never updated after insert. Totals, retries, and reconstructable state
 * all derive from this log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['initiate', 'callback', 'verify', 'refund']);

            $table->string('provider', 50)->default('clickpesa');
            $table->string('provider_reference', 100)->nullable();

            $table->enum('status', [
                'pending',
                'success',
                'failed',
                'error',
            ])->default('pending');

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();

            $table->string('error_message')->nullable();

            $table->timestamp('attempted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['payment_id', 'type']);
            $table->index('status');
            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};