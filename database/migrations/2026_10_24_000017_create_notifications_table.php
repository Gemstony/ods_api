<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications — in-app records and delivery tracking for SMS / push.
 *
 * One logical notification per row. `channels` records which delivery
 * channels were used (in_app, sms, push). Phase 13 dispatches each
 * channel as a separate job and updates the row.
 *
 * This table replaces Laravel's default Notifiable table — the User
 * model no longer uses the `Notifiable` trait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('type', 100);          // e.g. 'delivery.assigned', 'payment.completed'
            $table->string('title', 200);
            $table->text('body')->nullable();

            $table->json('data')->nullable();      // deep-link payload

            $table->json('channels');              // ["in_app","sms"] etc.

            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
}; 