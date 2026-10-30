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
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('identity_hash', 64);
            $table->string('name')->nullable();
            $table->string('preferred_language', 2)->default('en');
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['hospital_id', 'channel', 'identity_hash'],
                'patients_hospital_channel_identity_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
