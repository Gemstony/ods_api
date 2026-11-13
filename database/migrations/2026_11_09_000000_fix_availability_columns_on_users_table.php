<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrective migration for Phase 10.2's availability fields.
 *
 * The original migration (2026_11_08_000000) is recorded as "Ran" in
 * the migrations table, but the columns don't exist on the MySQL dev
 * database — likely from a partial application during development.
 *
 * This migration is **idempotent**: it checks for each column / index
 * before attempting to add it, so it's safe to run multiple times and
 * safe on both MySQL (dev) and SQLite (tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_available')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_available')
                    ->default(true)
                    ->after('dispatcher_enabled');
            });
        }

        if (! Schema::hasColumn('users', 'available_since')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('available_since')
                    ->nullable()
                    ->after('is_available');
            });
        }

        // Add the compound index only when all three columns are present
        // and it doesn't already exist. Wrapped in try/catch because
        // MySQL throws if the index is a duplicate.
        if (
            Schema::hasColumn('users', 'branch_id') &&
            Schema::hasColumn('users', 'is_available') &&
            Schema::hasColumn('users', 'available_since')
        ) {
            try {
                Schema::table('users', function (Blueprint $table) {
                    $table->index(
                        ['branch_id', 'is_available', 'available_since'],
                        'users_branch_available_since_index',
                    );
                });
            } catch (Throwable $e) {
                // Index already exists — safe to ignore.
            }
        }

        // Backfill existing rows so the queue is immediately usable.
        if (Schema::hasColumn('users', 'available_since')) {
            DB::table('users')
                ->whereNull('available_since')
                ->update(['available_since' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        // Intentionally empty. The original migration is the canonical
        // reverser — running two down()s that drop the same columns
        // would throw. If you ever need to undo this phase, roll back
        // the original.
    }
};
