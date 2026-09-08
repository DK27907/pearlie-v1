<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invites') && ! Schema::hasColumn('invites', 'expires_at')) {
            Schema::table('invites', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable()->after('token');
                $table->unsignedBigInteger('used_by')->nullable()->after('used_at');
                $table->foreign('used_by')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invites') && Schema::hasColumn('invites', 'expires_at')) {
            Schema::table('invites', function (Blueprint $table) {
                $table->dropForeign(['used_by']);
                $table->dropColumn(['expires_at', 'used_by']);
            });
        }
    }
};
