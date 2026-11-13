<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ClickPesa API call log (Phase 11.1).
 *
 * One row per HTTP round-trip to ClickPesa — auth, preview, initiate,
 * query, query-all. Append-only. Never updated after insert.
 *
 * Audit safety:
 *   - `request_headers` and `request_body` are REDACTED before storage
 *     (api-key, Authorization, phoneNumber). Amounts, order references,
 *     and response bodies are stored in full so a full incident can be
 *     reconstructed from this table alone.
 *   - `duration_ms` and `http_status_code` let you distinguish timeouts
 *     from 4xx business errors from 5xx outages without reading code.
 *   - `correlation_id` ties every call in one business flow (e.g. one
 *     payment initiation = auth + preview + initiate = 1 correlation_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clickpesa_api_logs', function (Blueprint $table) {
            $table->id();

            $table->uuid('correlation_id');
            $table->string('method', 10);
            $table->string('endpoint', 200);
            $table->string('full_url', 500);

            $table->json('request_headers')->nullable();
            $table->json('request_body')->nullable();

            $table->integer('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->json('response_headers')->nullable();

            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->default(0);

            $table->foreignId('payment_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->enum('triggered_by', [
                'api',
                'cli',
                'scheduler',
                'webhook',
                'system',
            ])->default('system');

            $table->timestamps();

            $table->index('correlation_id');
            $table->index(['payment_id', 'created_at']);
            $table->index(['endpoint', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clickpesa_api_logs');
    }
};