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
        Schema::table('hospitals', function (Blueprint $table): void {
            $table->string('site_header_text', 120)->nullable();
            $table->text('site_footer_text')->nullable();
            $table->string('site_logo_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table): void {
            $table->dropColumn(['site_header_text', 'site_footer_text', 'site_logo_path']);
        });
    }
};
