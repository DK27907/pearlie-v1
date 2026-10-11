<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitals', function (Blueprint $table): void {
            $table->boolean('patient_cancellation_enabled')->default(true);
            $table->boolean('patient_rescheduling_enabled')->default(true);
            $table->unsignedSmallInteger('cancellation_cutoff_hours')->default(24);
            $table->unsignedSmallInteger('rescheduling_cutoff_hours')->default(24);
            $table->decimal('late_cancellation_fee', 10, 2)->default(500);
            $table->boolean('deposits_refundable')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table): void {
            $table->dropColumn([
                'patient_cancellation_enabled',
                'patient_rescheduling_enabled',
                'cancellation_cutoff_hours',
                'rescheduling_cutoff_hours',
                'late_cancellation_fee',
                'deposits_refundable',
            ]);
        });
    }
};
