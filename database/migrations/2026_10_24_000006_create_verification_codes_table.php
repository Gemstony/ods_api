<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verification codes — DISTRIBUTOR_CODE and RECIPIENT_CODE.
 *
 * Per the ODS document:
 *   DISTRIBUTOR_CODE: used for Receive — distributor confirms receipt.
 *   RECIPIENT_CODE:   used for Send and Regional — recipient confirms receipt.
 *
 * Security:
 *   - Raw code is NEVER stored; only its SHA-256 hash.
 *   - Each row is single-use; verified codes get `verified_at` set.
 *   - Retries are counted; Phase 12 enforces a maximum.
 *   - Expiry is enforced by Phase 12's validation logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();

            $table->enum('code_type', ['distributor', 'recipient']);

            // SHA-256 hex of the raw code (64 chars).
            $table->string('code_hash', 64);

            // Who the code was issued to (distributor or recipient user).
            $table->foreignId('issued_to_user_id')->constrained('users');
            $table->timestamp('issued_at');

            // Populated when a delivery agent successfully verifies.
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users');

            // Retry + expiry guards.
            $table->timestamp('expires_at')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);

            $table->timestamps();

            $table->index(['delivery_id', 'code_type', 'verified_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};