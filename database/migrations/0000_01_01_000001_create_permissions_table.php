<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions — individual capabilities that can be granted to roles.
 *
 * Examples: 'manage_users', 'manage_branches', 'manage_pricing',
 * 'reconcile_finance', 'handle_packages', 'dispatch_regional'.
 *
 * Populated with defaults in the seeders (2.8). Middleware can check
 * `$user->hasPermission('manage_users')` for fine-grained enforcement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};