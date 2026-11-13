<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riders queue at their branch waiting for assignments. Instead of GPS
 * tracking, we use a FIFO queue keyed by `available_since`.
 *
 *   is_available    true  → rider is physically at branch, ready to work
 *                   false → rider is out on a delivery, on break, or off shift
 *   available_since when they joined the queue — the FIFO sort key
 *
 * Compound index supports the hot query:
 *   SELECT * FROM users
 *   WHERE branch_id = ? AND is_available = true AND status = 'active'
 *   ORDER BY available_since ASC
 *
 * Backfill: existing riders default to available since their creation
 * time. This is deliberately optimistic — if a rider is actually busy
 * right now, they'll be re-marked busy by the next assignment or by
 * their own `PATCH /rider/availability` call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_available')
                ->default(true)
                ->after('dispatcher_enabled');
            $table->timestamp('available_since')
                ->nullable()
                ->after('is_available');

            $table->index(
                ['branch_id', 'is_available', 'available_since'],
                'users_branch_available_since_index',
            );
        });

        // Backfill existing rows so the queue is immediately usable.
        DB::statement('UPDATE users SET available_since = created_at WHERE available_since IS NULL');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_branch_available_since_index');
            $table->dropColumn(['is_available', 'available_since']);
        });
    }
};
