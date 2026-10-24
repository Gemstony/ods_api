<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operating expenses — the "operating expenses" ledger the ODS document
 * requires as a separate record.
 *
 * Recorded by the Owner (or a delegated manager) for fuel, salaries, rent,
 * utilities, supplies, maintenance, and other operational costs. Phase 15
 * uses these rows to compute distributable surplus:
 *
 *   surplus = service_revenue - rider_payouts - dispatcher_payouts
 *             - transport_fares_owed - processing_charges - expenses
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->enum('category', [
                'fuel',
                'salary',
                'rent',
                'utilities',
                'supplies',
                'maintenance',
                'transport_provider', // money paid out to bus/cargo providers
                'other',
            ]);

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('TZS');
            $table->string('description');
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->constrained('users');

            $table->timestamp('occurred_at');
            $table->string('payment_reference', 100)->nullable();
            $table->string('attachment_path')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};