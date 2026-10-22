<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('hospitals')->update(['reminder_enabled' => false]);

        Schema::table('hospitals', function (Blueprint $table): void {
            $table->boolean('reminder_enabled')->default(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table): void {
            $table->boolean('reminder_enabled')->default(false)->change();
        });
    }
};
