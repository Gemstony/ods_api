<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles — the fixed set of five ODS user roles.
 *
 * The `name` column is the stable identifier used throughout the codebase
 * ('owner', 'manager', 'distributor', 'rider', 'receiver'). `display_name`
 * is the human-readable label shown in the UI.
 *
 * Must run BEFORE `0001_01_01_000000_create_users_table.php` because users
 * has a foreign key to this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->string('display_name', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};