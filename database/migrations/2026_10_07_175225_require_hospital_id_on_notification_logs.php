<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('notification_logs')->whereNull('hospital_id')->exists()) {
            throw new RuntimeException(
                'Cannot require notification log tenant IDs while unowned log rows exist.',
            );
        }

        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropForeign(['hospital_id']);
        });

        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->foreignId('hospital_id')->nullable(false)->change();
        });

        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->foreign('hospital_id')
                ->references('id')
                ->on('hospitals')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropForeign(['hospital_id']);
        });

        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->foreignId('hospital_id')->nullable()->change();
        });

        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->foreign('hospital_id')
                ->references('id')
                ->on('hospitals')
                ->nullOnDelete();
        });
    }
};
