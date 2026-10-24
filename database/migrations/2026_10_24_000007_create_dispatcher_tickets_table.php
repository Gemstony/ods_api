<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispatcher tickets — regional bus/cargo dispatch records.
 *
 * One ticket per regional delivery. Created by a Receiver who also has
 * `dispatcher_enabled = true` (Regional Dispatcher Agent flag from 2.1).
 *
 * The `transport_fare` is money owed to the bus/cargo provider — it is
 * explicitly NOT dispatcher income. Dispatcher compensation is tracked
 * separately in the `earnings` table (Phase 2.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatcher_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();

            // The Receiver acting as dispatcher for this ticket.
            $table->foreignId('dispatcher_id')->constrained('users');

            // ─── Transport provider ───────────────────────────────
            $table->string('provider_name', 100); // e.g., "Kilimanjaro Express"
            $table->enum('provider_type', ['bus', 'cargo', 'other']);
            $table->string('ticket_number', 100);

            // ─── Route ────────────────────────────────────────────
            $table->string('route_from', 100);
            $table->string('route_to', 100);

            // ─── Money owed to the provider (NOT dispatcher income) ─
            $table->decimal('transport_fare', 12, 2);
            $table->string('currency', 3)->default('TZS');

            // ─── Timestamps ───────────────────────────────────────
            $table->timestamp('issued_at');
            $table->timestamp('provider_confirmed_at')->nullable();
            $table->foreignId('provider_confirmed_by')->nullable()->constrained('users');

            // ─── Evidence ─────────────────────────────────────────
            // Photo of the ticket — stored on local server disk.
            $table->string('ticket_image_path')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('delivery_id');
            $table->index('dispatcher_id');
            $table->index('ticket_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatcher_tickets');
    }
};