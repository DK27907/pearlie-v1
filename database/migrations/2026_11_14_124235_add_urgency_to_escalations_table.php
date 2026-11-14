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
        Schema::table('escalations', function (Blueprint $table): void {
            $table->boolean('is_urgent')->default(false)->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('escalations', function (Blueprint $table): void {
            $table->dropIndex(['is_urgent']);
            $table->dropColumn('is_urgent');
        });
    }
};
