<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relax `clickpesa_callbacks.provider_event_id` to nullable (Phase 11.2).
 *
 * The original Phase 2 migration declared this column NOT NULL. But
 * ClickPesa webhooks do not always carry a unique provider event ID —
 * some payloads include only `orderReference`, `status`, and `amount`.
 * The controller correctly writes `null` when the field is absent; the
 * schema must allow it.
 *
 * `payment_transactions` remains the append-only state-transition log
 * and does not depend on this column for idempotency. Duplicate webhooks
 * are still handled by the payment state machine's terminal-state guard
 * (PaymentStatusMapper::canTransition), so relaxing this column does not
 * weaken exactly-once processing.
 *
 * down() refuses to run if rows with NULL provider_event_id exist —
 * reverting would fail mid-ALTER on MySQL and silent-corrupt on SQLite.
 * The operator must decide whether to backfill or accept the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clickpesa_callbacks', function (Blueprint $table) {
            $table->string('provider_event_id', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        $nullCount = DB::table('clickpesa_callbacks')
            ->whereNull('provider_event_id')
            ->count();

        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Cannot rollback: {$nullCount} clickpesa_callbacks row(s) have NULL "
                . "provider_event_id. Backfill or delete them before rolling back."
            );
        }

        Schema::table('clickpesa_callbacks', function (Blueprint $table) {
            $table->string('provider_event_id', 255)->change();
        });
    }
};