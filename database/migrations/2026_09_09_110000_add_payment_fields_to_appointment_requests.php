<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table) {
            $table->unsignedInteger('booking_fee')->default(500);
            $table->string('payment_status')->default('pending')->index();
            $table->string('mpesa_phone')->nullable();
            $table->string('mpesa_checkout_request_id')->nullable()->unique();
            $table->string('mpesa_merchant_request_id')->nullable();
            $table->unsignedInteger('mpesa_result_code')->nullable();
            $table->text('mpesa_result_description')->nullable();
            $table->string('mpesa_receipt')->nullable();
            $table->timestamp('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table) {
            $table->dropUnique(['mpesa_checkout_request_id']);
            $table->dropColumn([
                'booking_fee',
                'payment_status',
                'mpesa_phone',
                'mpesa_checkout_request_id',
                'mpesa_merchant_request_id',
                'mpesa_result_code',
                'mpesa_result_description',
                'mpesa_receipt',
                'paid_at',
            ]);
        });
    }
};
