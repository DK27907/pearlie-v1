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
        Schema::table('announcements', function (Blueprint $table): void {
            $table->string('reminder_stage', 8)->nullable();
            $table->dropUnique(['hospital_id', 'reminder_appointment_id']);
            $table->unique(
                ['hospital_id', 'reminder_appointment_id', 'reminder_stage'],
                'ann_hospital_reminder_stage_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropUnique('ann_hospital_reminder_stage_unique');
            $table->unique(['hospital_id', 'reminder_appointment_id']);
            $table->dropColumn('reminder_stage');
        });
    }
};
