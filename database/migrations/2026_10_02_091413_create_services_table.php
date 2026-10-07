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
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hospital_id')->constrained('hospitals')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('category', 60)->nullable();
            $table->string('requires_specialty', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['hospital_id', 'is_active']);
            $table->index(['hospital_id', 'category']);
        });

        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->foreignId('service_id')
                ->nullable()
                ->constrained('services')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_id');
        });

        Schema::dropIfExists('services');
    }
};
