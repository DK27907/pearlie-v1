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
        Schema::table('payment_events', function (Blueprint $table): void {
            $table->index('hospital_id', 'payment_events_hospital_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table): void {
            $table->dropIndex('payment_events_hospital_id_index');
        });
    }
};
