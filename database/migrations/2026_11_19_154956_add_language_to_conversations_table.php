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
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('language', 10)->default('en')->after('channel');
            $table->index(['hospital_id', 'created_at', 'language'], 'conversations_tenant_month_language_index');
            $table->index(['created_at', 'language', 'hospital_id'], 'conversations_platform_month_language_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_tenant_month_language_index');
            $table->dropIndex('conversations_platform_month_language_index');
            $table->dropColumn('language');
        });
    }
};
