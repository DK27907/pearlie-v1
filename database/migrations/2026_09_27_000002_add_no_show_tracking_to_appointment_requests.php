<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->string('email')->nullable();
            $table->timestamp('marked_no_show_at')->nullable();
            $table->string('no_show_reason')->nullable();
            $table->index(['status', 'preferred_date'], 'appointments_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->dropIndex('appointments_status_date_idx');
            $table->dropColumn(['email', 'marked_no_show_at', 'no_show_reason']);
        });
    }
};
