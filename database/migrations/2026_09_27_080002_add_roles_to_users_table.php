<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', ['super_admin', 'hospital_admin', 'doctor', 'patient'])
                ->default('patient');
            $table->boolean('is_super_admin')->default(false);
            $table->index('role');
        });

        DB::table('users')->where('is_admin', true)->update(['role' => 'hospital_admin']);
        DB::table('users')->where('is_doctor', true)->update(['role' => 'doctor']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'is_super_admin']);
        });
    }
};
