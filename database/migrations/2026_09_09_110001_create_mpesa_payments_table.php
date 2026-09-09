<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('checkout_request_id')->unique();
            $table->string('merchant_request_id')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedInteger('amount')->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('result_code')->nullable();
            $table->text('result_description')->nullable();
            $table->string('mpesa_receipt')->nullable();
            $table->json('callback_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_payments');
    }
};
