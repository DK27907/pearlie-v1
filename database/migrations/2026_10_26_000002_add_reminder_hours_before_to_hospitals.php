<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitals', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reminder_hours_before')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table): void {
            $table->dropColumn('reminder_hours_before');
        });
    }
};
