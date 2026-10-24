<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deliveries — the core request record for all three services.
 *
 * Service-specific nullability:
 *   Receive:  pickup_branch_id ✓, pickup_address ✗, recipient_* ✗
 *   Send:     pickup_branch_id ✗, pickup_address ✓, recipient_* ✓
 *   Regional: pickup_branch_id ✓, destination_region/district/bus_stop ✓, recipient_* ✓
 *
 * Pricing fields are snapshots at creation time — they must never be
 * recalculated from current config, or historical deliveries would drift.
 *
 * Status enum covers the full lifecycle. Different services skip states:
 *   Local (Receive/Send): paid → batched → assigned → accepted → picked_up → delivered
 *   Regional:             paid → batched → assigned → dispatched → delivered
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();

            // ─── Identity ──────────────────────────────────────────
            $table->string('tracking_id', 30)->unique(); // ODS-YYYYMMDD-XXXXXX

            // ─── Core references ──────────────────────────────────
            $table->foreignId('distributor_id')->constrained('users');
            $table->foreignId('pickup_branch_id')->nullable()->constrained('branches');

            // ─── Service type ─────────────────────────────────────
            $table->enum('service_type', ['receive', 'send', 'regional']);

            // ─── Status ───────────────────────────────────────────
            $table->enum('status', [
                'pending_payment',
                'paid',
                'batched',
                'assigned',
                'accepted',
                'picked_up',
                'dispatched',
                'delivered',
                'cancelled',
                'failed',
                'expired',
            ])->default('pending_payment');

            // ─── Pickup location (Send uses distributor's location) ─
            $table->text('pickup_address')->nullable();
            $table->decimal('pickup_latitude', 10, 7)->nullable();
            $table->decimal('pickup_longitude', 10, 7)->nullable();
            $table->string('pickup_contact_phone', 20)->nullable();

            // ─── Destination location ─────────────────────────────
            $table->text('destination_address')->nullable();
            $table->decimal('destination_latitude', 10, 7)->nullable();
            $table->decimal('destination_longitude', 10, 7)->nullable();
            $table->string('destination_region', 100)->nullable();
            $table->string('destination_district', 100)->nullable();
            $table->string('destination_bus_stop', 150)->nullable();

            // ─── Recipient (Send + Regional) ──────────────────────
            $table->string('recipient_name', 100)->nullable();
            $table->string('recipient_phone', 20)->nullable();

            // ─── Pricing snapshot ─────────────────────────────────
            // Reproduce historical math: distance × rate, then min, then round.
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->decimal('rate_per_km', 10, 2)->nullable();
            $table->decimal('minimum_fee', 12, 2)->nullable();
            $table->unsignedInteger('rounding_increment')->nullable();
            $table->decimal('calculated_fee', 12, 2)->nullable(); // raw: distance × rate
            $table->decimal('ods_delivery_fee', 12, 2)->nullable(); // final ODS charge
            $table->decimal('bus_cargo_fare', 12, 2)->nullable();   // regional only
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_reason', 100)->nullable();
            $table->decimal('total_amount', 12, 2)->nullable();    // ods + bus - discount
            $table->string('currency', 3)->default('TZS');

            // ─── Assignment ───────────────────────────────────────
            $table->foreignId('rider_id')->nullable()->constrained('users');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('assignment_expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // ─── Regional dispatch ────────────────────────────────
            $table->foreignId('dispatcher_id')->nullable()->constrained('users');
            $table->timestamp('dispatched_at')->nullable();

            // ─── Batching / grouping (populated in Phase 8/10) ────
            $table->string('batch_id', 50)->nullable();
            $table->timestamp('batch_window_start')->nullable();
            $table->timestamp('batch_window_end')->nullable();
            $table->string('assignment_group_id', 50)->nullable();

            // ─── Cancellation ─────────────────────────────────────
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->text('cancellation_reason')->nullable();

            // ─── Extra ────────────────────────────────────────────
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ─── Indexes for common queries ───────────────────────
            $table->index('service_type');
            $table->index('status');
            $table->index(['distributor_id', 'status']);
            $table->index(['rider_id', 'status']);
            $table->index(['dispatcher_id', 'status']);
            $table->index(['pickup_branch_id', 'status']);
            $table->index('batch_id');
            $table->index('assignment_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};