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
        Schema::create('appointment_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 32);
            $table->string('action', 32);
            $table->date('old_date')->nullable();
            $table->date('new_date')->nullable();
            $table->foreignId('old_doctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_doctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->time('old_start_time')->nullable();
            $table->time('new_start_time')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['hospital_id', 'appointment_request_id', 'created_at'], 'appointment_history_tenant_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_histories');
    }
};
