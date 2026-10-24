<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches — physical NeoLife pickup points managed under ODS.
 *
 * Per the ODS document: "Register each branch with its address, GPS
 * coordinates, operating hours, and assigned manager."
 *
 * `code` is a short human-readable identifier (e.g. "DSM-01") — my addition,
 * not required by the spec, but every operational system ends up needing a
 * short reference. Unique across branches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);
            $table->string('code', 20)->unique(); // e.g. "DSM-01"

            $table->text('address');

            // GPS coordinates — decimal(10, 7) => ±90.0000000 / ±180.0000000
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            // Operating hours as JSON. Format:
            //   {
            //     "monday":    {"open": "08:00", "close": "18:00"},
            //     "tuesday":   {"open": "08:00", "close": "18:00"},
            //     ...
            //     "sunday":    null
            //   }
            // Nullable in the DB; Phase 6 FormRequest requires it on create.
            $table->json('operating_hours')->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            // Who created the branch — typically the Owner.
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};