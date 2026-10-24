<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit logs — append-only forensic trail.
 *
 * Every sensitive action (role change, branch assignment, config edit,
 * verification code entry, financial adjustment) writes one row. Never
 * updated; never deleted except by admin purge.
 *
 * Snapshot fields (actor_label, actor_role, subject_label) preserve
 * context even if the referenced user is later deleted or their role
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // ─── Who ─────────────────────────────────────────────
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 32)->nullable();
            $table->string('actor_label', 150)->nullable();

            // ─── What ────────────────────────────────────────────
            $table->string('action', 100);          // e.g. 'user.created', 'delivery.assigned'
            $table->string('subject_type', 100)->nullable();   // e.g. 'user', 'delivery'
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 200)->nullable();

            $table->string('description', 500);

            // ─── Change data ─────────────────────────────────────
            $table->json('changes')->nullable();

            // ─── Where ───────────────────────────────────────────
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // ─── When ────────────────────────────────────────────
            $table->timestamp('created_at')->useCurrent();

            // ─── Indexes ─────────────────────────────────────────
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};