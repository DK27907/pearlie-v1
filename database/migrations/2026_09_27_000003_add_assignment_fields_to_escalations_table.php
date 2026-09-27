<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escalations', function (Blueprint $table): void {
            $table->foreignId('assigned_worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('escalations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_worker_id');
            $table->dropConstrainedForeignId('resolved_by_id');
            $table->dropColumn(['claimed_at', 'resolved_at']);
        });
    }
};
