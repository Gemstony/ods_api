<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payouts — batches of earnings paid to a user (rider or dispatcher).
 *
 * Runs before `earnings` in migration order because `earnings.payout_id`
 * references `payouts.id`. Individual earnings are grouped into a payout
 * when the Owner approves a payment batch (Phase 11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('TZS');

            $table->enum('method', ['mobile', 'bank', 'cash'])->default('mobile');
            $table->string('reference', 40)->unique();

            $table->enum('status', [
                'pending',
                'processing',
                'paid',
                'failed',
                'cancelled',
            ])->default('pending');

            $table->timestamp('requested_at');
            $table->timestamp('processed_at')->nullable();

            $table->foreignId('processed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('failure_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};