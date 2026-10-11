<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // No-op: this migration previously dropped a temporary roles table that conflicts with
        // spatie/laravel-permission. Leave as no-op to avoid deleting permission tables created by Spatie.

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // no-op: original simple roles table will not be recreated automatically
    }
};
