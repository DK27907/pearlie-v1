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
        Schema::table('appointment_requests', function (Blueprint $table) {
            $table->timestamp('feedback_requested_at')->nullable();
            $table->unsignedTinyInteger('feedback_score')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->char('feedback_identity_hash', 64)->nullable();
            $table->index(
                ['status', 'feedback_requested_at', 'updated_at'],
                'appointment_follow_up_due_index',
            );
            $table->index(
                ['hospital_id', 'feedback_identity_hash', 'status', 'preferred_date'],
                'appointment_feedback_identity_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table) {
            $table->dropIndex('appointment_follow_up_due_index');
            $table->dropIndex('appointment_feedback_identity_index');
            $table->dropColumn([
                'feedback_requested_at',
                'feedback_score',
                'feedback_comment',
                'feedback_identity_hash',
            ]);
        });
    }
};
