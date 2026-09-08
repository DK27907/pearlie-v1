<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('subcategory')->nullable();
            $table->json('keywords');
            $table->text('question');
            $table->text('answer');
            $table->string('source');
            $table->date('last_updated');
            $table->string('approved_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_bases');
    }
};