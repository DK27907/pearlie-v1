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
        Schema::create('mpesa_callback_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('checkout_request_id', 128)->nullable();
            $table->string('hospital_slug')->nullable();
            $table->char('payload_hash', 64);
            $table->longText('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->index();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(
                ['checkout_request_id', 'payload_hash'],
                'mpesa_callback_inbox_deduplication_unique',
            );
            $table->index(
                ['status', 'available_at', 'id'],
                'mpesa_callback_inbox_retry_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_callback_inbox');
    }
};
