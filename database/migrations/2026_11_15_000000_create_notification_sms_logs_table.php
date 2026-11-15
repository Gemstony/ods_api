<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for every Beem SMS HTTP call (Phase 13.2).
 *
 * Mirrors `clickpesa_api_logs` (11.1) in shape and redaction policy.
 * Append-only; never updated after insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_sms_logs', function (Blueprint $table) {
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

            // Beem's platform code (100 = success) when present.
            $table->integer('beem_code')->nullable();
            $table->string('beem_message', 255)->nullable();
            $table->string('beem_request_id', 100)->nullable();

            $table->foreignId('notification_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->enum('triggered_by', [
                'cli',
                'scheduler',
                'api',
                'system',
            ])->default('scheduler');

            $table->timestamps();

            $table->index('correlation_id');
            $table->index(['notification_id', 'created_at']);
            $table->index('beem_request_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_sms_logs');
    }
};