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
        if (! Schema::hasColumn('hospitals', 'deposit_amount')) {
            Schema::table('hospitals', function (Blueprint $table): void {
                $table->unsignedInteger('deposit_amount')->default(500)->after('subscription_plan');
            });
        }

        if (! Schema::hasColumn('hospitals', 'slot_duration_minutes')) {
            Schema::table('hospitals', function (Blueprint $table): void {
                $table->unsignedSmallInteger('slot_duration_minutes')->default(30)->after('deposit_amount');
            });
        }

        if (! Schema::hasColumn('hospitals', 'auto_confirm_paid_appointments')) {
            Schema::table('hospitals', function (Blueprint $table): void {
                $table->boolean('auto_confirm_paid_appointments')->default(false)->after('slot_duration_minutes');
            });
        }

        if (! Schema::hasColumn('hospitals', 'business_hours')) {
            Schema::table('hospitals', function (Blueprint $table): void {
                $table->json('business_hours')->nullable()->after('auto_confirm_paid_appointments');
            });
        }

        if (! Schema::hasColumn('hospitals', 'notification_preferences')) {
            Schema::table('hospitals', function (Blueprint $table): void {
                $table->json('notification_preferences')->nullable()->after('business_hours');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'notification_preferences',
            'business_hours',
            'auto_confirm_paid_appointments',
        ] as $column) {
            if (Schema::hasColumn('hospitals', $column)) {
                Schema::table('hospitals', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
