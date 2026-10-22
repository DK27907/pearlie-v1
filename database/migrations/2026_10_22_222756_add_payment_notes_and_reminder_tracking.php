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
            $table->text('payment_notes')->nullable();
        });

        Schema::table('announcements', function (Blueprint $table): void {
            $table->foreignId('reminder_appointment_id')
                ->nullable()
                ->constrained('appointment_requests')
                ->cascadeOnDelete();
            $table->unique(['hospital_id', 'reminder_appointment_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropUnique(['hospital_id', 'reminder_appointment_id']);
            $table->dropConstrainedForeignId('reminder_appointment_id');
        });

        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->dropColumn('payment_notes');
        });
    }
};
