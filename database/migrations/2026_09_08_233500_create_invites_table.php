<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invites')) {
            Schema::create('invites', function (Blueprint $table) {
                $table->id();
                $table->string('email')->nullable();
                $table->string('token')->unique();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamps();

                $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invites');
    }
};
