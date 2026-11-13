<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ClickPesa token cache (Phase 11.1).
 *
 * One active row per (client_id_hash). When a token expires, it's
 * overwritten in-place — no history kept here. Token history is
 * reconstructable from clickpesa_api_logs (every auth call is logged).
 *
 * Why a DB table and not Laravel Cache:
 *   - Survives cache:clear (common during deploys)
 *   - Visible in SQL for debugging
 *   - Multi-process safe (scheduler + HTTP serve share the same token)
 *   - Cleanly portable to multi-instance in Phase 15
 *
 * Why hash the client_id:
 *   - Client ID isn't a secret, but treating it carefully means no
 *     accidental leak in logs
 *   - Multi-merchant support later (one row per client_id_hash)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clickpesa_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('client_id_hash', 64)->unique();
            $table->text('access_token');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clickpesa_tokens');
    }
};