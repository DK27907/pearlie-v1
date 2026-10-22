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
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->text('message');
            $table->json('audience_filter')->nullable();
            $table->string('channel');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->index(['hospital_id', 'status', 'scheduled_for']);
            $table->index(['hospital_id', 'type', 'created_at']);
        });

        Schema::create('announcement_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_name');
            $table->string('recipient_phone');
            $table->text('rendered_message');
            $table->timestamp('sent_at')->nullable();
            $table->string('delivery_status')->default('queued');
            $table->string('provider_message_id')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'appointment_request_id']);
            $table->index(['announcement_id', 'delivery_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
        Schema::dropIfExists('announcements');
    }
};
