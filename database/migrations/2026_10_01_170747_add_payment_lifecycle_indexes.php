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
        Schema::table('slot_holds', function (Blueprint $table): void {
            $table->index(['doctor_id', 'slot_start_at'], 'slot_holds_doctor_slot_start_index');
        });

        Schema::table('mpesa_payments', function (Blueprint $table): void {
            $table->unique('mpesa_receipt', 'mpesa_payments_mpesa_receipt_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mpesa_payments', function (Blueprint $table): void {
            $table->dropUnique('mpesa_payments_mpesa_receipt_unique');
        });

        Schema::table('slot_holds', function (Blueprint $table): void {
            $table->dropIndex('slot_holds_doctor_slot_start_index');
        });
    }
};
