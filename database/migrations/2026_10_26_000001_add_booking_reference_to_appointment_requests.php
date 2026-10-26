<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->string('booking_reference', 35)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->dropUnique(['booking_reference']);
            $table->dropColumn('booking_reference');
        });
    }
};
