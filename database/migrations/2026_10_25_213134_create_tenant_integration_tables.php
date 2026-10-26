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
        if (! Schema::hasTable('tenant_api_tokens')) {
            Schema::create('tenant_api_tokens', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->string('name', 80);
                $table->char('token_hash', 64)->unique();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('webhook_subscriptions')) {
            Schema::create('webhook_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->string('url', 2048);
                $table->text('secret');
                $table->json('events');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['hospital_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('webhook_deliveries')) {
            Schema::create('webhook_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->foreignId('webhook_subscription_id')->constrained()->cascadeOnDelete();
                $table->uuid('delivery_id')->unique();
                $table->string('event', 100);
                $table->json('payload');
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('next_attempt_at')->nullable()->index();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
                $table->index(
                    ['hospital_id', 'webhook_subscription_id', 'created_at'],
                    'webhook_deliveries_tenant_lookup',
                );
            });
        }

        $deliveryIndexColumns = ['hospital_id', 'webhook_subscription_id', 'created_at'];

        if (! Schema::hasIndex('webhook_deliveries', $deliveryIndexColumns)) {
            Schema::table('webhook_deliveries', function (Blueprint $table) use ($deliveryIndexColumns): void {
                $table->index($deliveryIndexColumns, 'webhook_deliveries_tenant_lookup');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
        Schema::dropIfExists('tenant_api_tokens');
    }
};
