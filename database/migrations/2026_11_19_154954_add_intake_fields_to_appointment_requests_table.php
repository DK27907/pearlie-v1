<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->timestamp('intake_completed_at')->nullable();
            $table->json('intake_data')->nullable();
            $table->text('intake_token')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'intake_completed_at',
                'intake_data',
                'intake_token',
            ]);
        });
    }
};
