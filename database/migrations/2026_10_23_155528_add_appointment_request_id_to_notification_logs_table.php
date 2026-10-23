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
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->foreignId('appointment_request_id')
                ->nullable()
                ->after('hospital_id')
                ->constrained('appointment_requests')
                ->nullOnDelete();
            $table->index(
                ['hospital_id', 'appointment_request_id', 'created_at'],
                'notification_logs_tenant_appointment_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropIndex('notification_logs_tenant_appointment_index');
            $table->dropConstrainedForeignId('appointment_request_id');
        });
    }
};
