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
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->string('payment_method')->nullable();
            $table->string('payment_due_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('payment_collected_amount', 10, 2)->nullable();
        });

        Schema::table('hospitals', function (Blueprint $table): void {
            $table->string('receipt_letterhead_path')->nullable();
            $table->string('receipt_footer_path')->nullable();
            $table->text('receipt_footer_text')->nullable();
            $table->string('receipt_signature_path')->nullable();
            $table->string('receipt_prefix')->default('RCPT');
            $table->string('receipt_title')->default('Payment Receipt');
            $table->boolean('receipt_show_kra_pin')->default(false);
            $table->string('receipt_kra_pin')->nullable();
            $table->boolean('etims_enabled')->default(false);
            $table->string('etims_kra_pin')->nullable();
            $table->string('etims_environment')->nullable();
            $table->string('etims_device_id')->nullable();
            $table->boolean('reminder_enabled')->default(false)->index();
            $table->string('reminder_message_template', 1000)->default(
                'Hello {patient_name}, this is a reminder of your appointment with {doctor_name} tomorrow at {appointment_time}. Reply STOP to opt out.',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paid_by_user_id');
            $table->dropColumn([
                'payment_method',
                'payment_due_at',
                'payment_collected_amount',
            ]);
        });

        Schema::table('hospitals', function (Blueprint $table): void {
            $table->dropIndex(['reminder_enabled']);
            $table->dropColumn([
                'receipt_letterhead_path',
                'receipt_footer_path',
                'receipt_footer_text',
                'receipt_signature_path',
                'receipt_prefix',
                'receipt_title',
                'receipt_show_kra_pin',
                'receipt_kra_pin',
                'etims_enabled',
                'etims_kra_pin',
                'etims_environment',
                'etims_device_id',
                'reminder_enabled',
                'reminder_message_template',
            ]);
        });
    }
};
