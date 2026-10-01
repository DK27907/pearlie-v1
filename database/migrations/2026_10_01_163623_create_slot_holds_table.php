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
        Schema::create('slot_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('appointment_request_id')->nullable()
                ->constrained('appointment_requests')->cascadeOnDelete();
            $table->dateTime('slot_start_at');
            $table->dateTime('slot_end_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['hospital_id', 'doctor_id', 'slot_start_at'], 'slot_holds_slot_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slot_holds');
    }
};
