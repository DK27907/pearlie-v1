<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escalations', function (Blueprint $table): void {
            $table->string('user_phone', 32)->nullable()->after('user_message');
        });
    }

    public function down(): void
    {
        Schema::table('escalations', function (Blueprint $table): void {
            $table->dropColumn('user_phone');
        });
    }
};
