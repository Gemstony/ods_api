<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the FK constraint on `users.branch_id` pointing to `branches.id`.
 *
 * The column itself was created in 2.1 (when `branches` didn't exist yet).
 * Now that `branches` exists, we can add the constraint. This runs in a
 * *separate* migration so that 2.1 remains self-contained and re-runnable.
 *
 * Behaviour: if a branch is hard-deleted, users still pointing at it will
 * have their `branch_id` set to null. Soft-deletes don't trigger the FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });
    }
};