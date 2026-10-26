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
        if (! Schema::hasColumn('appointment_requests', 'booking_source')) {
            Schema::table('appointment_requests', function (Blueprint $table): void {
                $table->string('booking_source', 32)->nullable()->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The column may predate this guarded migration in an existing environment.
    }
};
