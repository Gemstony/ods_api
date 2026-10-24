<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings — flexible key-value store for the remaining configurable
 * categories the ODS document lists: location, batching, finance,
 * payments, security, notifications.
 *
 * Pricing and rewards live in `pricing_config` (structured, singleton).
 * Everything else lives here.
 *
 * `value` is JSON-encoded so a setting can be a scalar, an array, or an
 * object. The Setting model decodes it transparently.
 *
 * `is_public` = true means unauthenticated clients (like the Flutter app
 * before login) may read it — useful for app-level config like minimum
 * supported version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->longText('value'); // JSON-encoded
            $table->string('category', 50); // location, batching, finance, payments, security, notifications, app
            $table->string('description')->nullable();
            $table->boolean('is_public')->default(false);

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('category');
            $table->index('is_public');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};