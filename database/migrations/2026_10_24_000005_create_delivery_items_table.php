<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery items — one row per order reference or product line.
 *
 * Two input methods per the ODS document, both stored in this table:
 *
 *   M-Commerce (EXGO):
 *     exgo_order_id = 'EXGO-12345'
 *     product fields = null (unless the app retrieves them from NeoLife)
 *
 *   WhatsApp (manual table):
 *     exgo_order_id = null
 *     product_name = 'Salmon Oil', product_code = 'SLM-001', quantity = 2
 *
 * A single row may carry BOTH (if the app can verify EXGO order details
 * and the distributor also supplies them), or NEITHER is disallowed —
 * enforced at the controller layer, not with a DB CHECK constraint
 * (MySQL constraint support varies).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();

            // ─── EXGO order reference (M-Commerce) ────────────────
            $table->string('exgo_order_id', 100)->nullable();

            // ─── Product details (WhatsApp / manual) ──────────────
            $table->string('product_name', 150)->nullable();
            $table->string('product_code', 50)->nullable();
            $table->unsignedInteger('quantity')->nullable();

            // ─── Common ───────────────────────────────────────────
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['delivery_id', 'sort_order']);
            $table->index('exgo_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_items');
    }
};