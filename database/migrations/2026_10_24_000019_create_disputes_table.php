<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disputes — the ODS "dispute handling" mechanism.
 *
 * Disputes can be opened by any user about any subject: a delivery, a
 * payment, a package's condition, a compensation error, or something
 * else. `delivery_id` is nullable so the row can exist without a
 * specific delivery. `attachments` holds server-side file paths for
 * evidence (uploaded in Phase 13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('against_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('category', [
                'delivery',
                'payment',
                'package',
                'compensation',
                'other',
            ]);

            $table->string('subject', 200);
            $table->text('description');

            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', ['open', 'in_review', 'resolved', 'closed', 'escalated'])
                ->default('open');

            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->json('attachments')->nullable(); // array of file paths

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['delivery_id', 'status']);
            $table->index(['opened_by', 'status']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};