<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refresh tokens — long-lived, revocable tokens used to mint new access
 * JWTs without forcing the user to log in again.
 *
 * Security:
 *   - The raw token is never stored; only its SHA-256 hash (`token_hash`).
 *   - Each row is single-use: on refresh, the row is marked revoked and a
 *     new one is inserted (token rotation).
 *   - Logout revokes all tokens for a user on the current device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique(); // SHA-256 hex
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('device_name', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};