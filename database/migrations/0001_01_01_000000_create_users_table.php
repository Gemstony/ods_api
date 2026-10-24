<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users — a single table for all five roles.
 *
 * Role-specific fields are nullable so any user can exist in this table
 * regardless of role. Which fields are required for which role is enforced
 * at the controller/FormRequest layer, not the DB.
 *
 * Role-specific field map:
 *   Owner:        role_id, name, email, phone, password
 *   Manager:      … + (branch_id is added in 2.2 when Branch model lands)
 *   Distributor:  … + distributor_id
 *   Rider:        … + nida, sex, date_of_birth, vehicle_type,
 *                     vehicle_color, vehicle_ownership, plate_number, branch_id
 *   Receiver:     … + branch_id, dispatcher_enabled (optional flag)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // ─── Role ────────────────────────────────────────────
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();

            // ─── Identity ────────────────────────────────────────
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->string('phone', 20)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // ─── Status ──────────────────────────────────────────
            // 'active' default — controllers set 'pending' for
            // Distributor self-registration, and 'suspended'/'deactivated'
            // for admin actions.
            $table->enum('status', ['pending', 'active', 'suspended', 'deactivated'])
                ->default('active');

            // ─── Branch binding ──────────────────────────────────
            // Plain column for now — FK constraint is added in the
            // branches migration (2.2) once the branches table exists.
            $table->unsignedBigInteger('branch_id')->nullable();

            // ─── Distributor-specific ────────────────────────────
            $table->string('distributor_id', 50)->nullable()->unique();

            // ─── Rider-specific ──────────────────────────────────
            $table->string('nida', 20)->nullable();
            $table->enum('sex', ['male', 'female', 'other'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('vehicle_type', 50)->nullable();
            $table->string('vehicle_color', 50)->nullable();
            $table->enum('vehicle_ownership', ['owned', 'rented', 'company'])->nullable();
            $table->string('plate_number', 20)->nullable();

            // ─── Additional permissions (regional dispatcher) ────
            $table->boolean('dispatcher_enabled')->default(false);

            // ─── Laravel auth plumbing ───────────────────────────
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            // ─── Indexes ─────────────────────────────────────────
            $table->index('status');
            $table->index('branch_id');
            $table->index('dispatcher_enabled');
        });

        // ─── Laravel password reset (unchanged from default) ─────
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // ─── Laravel sessions (unchanged from default) ───────────
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};