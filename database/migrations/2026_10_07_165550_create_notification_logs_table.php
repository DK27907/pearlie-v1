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
        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hospital_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel');
            $table->string('provider');
            $table->string('status');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('provider_response')->nullable();
            $table->timestamps();
            $table->index(['hospital_id', 'channel', 'status', 'created_at'], 'notification_logs_tenant_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
