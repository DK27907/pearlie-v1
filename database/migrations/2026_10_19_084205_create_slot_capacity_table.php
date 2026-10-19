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
        Schema::create('slot_capacity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->date('slot_date');
            $table->time('slot_time');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('booked_count')->default(0);
            $table->timestamps();
            $table->unique(
                ['hospital_id', 'doctor_id', 'slot_date', 'slot_time'],
                'slot_capacity_slot_unique',
            );
            $table->index('slot_date', 'slot_capacity_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slot_capacity');
    }
};
