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
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mpesa_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_number');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method');
            $table->timestamp('issued_at');
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pdf_path')->nullable();
            $table->string('etims_status')->default('not_applicable');
            $table->string('etims_invoice_number')->nullable();
            $table->text('etims_qr_data')->nullable();
            $table->timestamp('etims_submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['hospital_id', 'receipt_number']);
            $table->unique(['hospital_id', 'appointment_request_id']);
            $table->index(['hospital_id', 'issued_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
