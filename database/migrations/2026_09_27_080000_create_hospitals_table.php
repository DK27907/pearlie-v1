<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospitals', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_url')->nullable();
            $table->string('primary_color', 7)->default('#0a2f44');
            $table->string('secondary_color', 7)->default('#1a5276');
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('county')->nullable();
            $table->string('phone')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->string('whatsapp_phone_number_id')->nullable();
            $table->text('whatsapp_access_token')->nullable();
            $table->text('whatsapp_app_secret')->nullable();
            $table->text('whatsapp_verify_token')->nullable();
            $table->string('whatsapp_api_version')->default('v20.0');
            $table->string('mpesa_shortcode')->nullable();
            $table->text('mpesa_consumer_key')->nullable();
            $table->text('mpesa_consumer_secret')->nullable();
            $table->text('mpesa_passkey')->nullable();
            $table->decimal('deposit_amount', 10, 2)->default(500);
            $table->unsignedInteger('slot_duration_minutes')->default(30);
            $table->unsignedInteger('no_show_grace_minutes')->default(30);
            $table->string('hours_emergency')->default('24/7');
            $table->string('hours_outpatient')->default('8:00 AM - 6:00 PM, Mon-Sat');
            $table->string('default_language', 8)->default('en');
            $table->json('supported_languages')->default(json_encode(['en', 'sw']));
            $table->enum('subscription_plan', ['starter', 'professional', 'enterprise'])->default('starter');
            $table->enum('subscription_status', ['active', 'trial', 'suspended'])->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->index('is_active');
            $table->index('subscription_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospitals');
    }
};
