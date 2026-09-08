<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->index();
            $table->text('user_message');
            $table->text('ai_response')->nullable();
            $table->float('confidence_score', 5, 2)->nullable();
            $table->string('channel')->default('web');
            $table->boolean('escalated')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};