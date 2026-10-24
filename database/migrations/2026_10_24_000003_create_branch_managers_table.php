<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch manager assignments — an append-only history of who ran each branch.
 *
 * Per the ODS document: "Once a branch manager is registered and assigned,
 * that manager takes full operational control of their assigned branch."
 *
 * A row is "active" while `unassigned_at` is null. When the Owner swaps
 * managers, the old row is closed (`unassigned_at` set) and a new row is
 * opened — history is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_managers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            // The manager being assigned (a user with role 'manager').
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Who performed the assignment — typically the Owner.
            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('assigned_at');

            $table->timestamp('unassigned_at')->nullable();

            $table->foreignId('unassigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Fast lookup for "current manager of branch X" and
            // "branches user Y currently manages".
            $table->index(['branch_id', 'unassigned_at']);
            $table->index(['user_id', 'unassigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_managers');
    }
};