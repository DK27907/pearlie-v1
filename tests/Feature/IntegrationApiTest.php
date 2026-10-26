<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhookDelivery;
use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\TenantApiToken;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\WebhookUrlGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_api_requires_a_valid_bearer_token(): void
    {
        $this->getJson('/api/v1/appointments')->assertUnauthorized();
        $this->getJson('/api/v1/appointments', [
            'Authorization' => 'Bearer pvl_'.str_repeat('a', 64),
        ])->assertUnauthorized();
    }

    public function test_token_reads_only_its_tenant_and_returns_no_patient_identifiers(): void
    {
        $firstHospital = $this->createHospital('api-first');
        $secondHospital = $this->createHospital('api-second');
        app()->instance('currentHospital', $firstHospital);
        $plainTextToken = 'pvl_'.bin2hex(random_bytes(32));
        $firstHospital->apiTokens()->create([
            'name' => 'Reporting',
            'token_hash' => hash('sha256', $plainTextToken),
        ]);
        $firstAppointment = AppointmentRequest::factory()->create([
            'hospital_id' => $firstHospital->id,
            'name' => 'Private Patient Name',
            'phone' => '254712345678',
            'email' => 'private@example.org',
            'reason' => 'Private medical notes',
        ]);

        app()->instance('currentHospital', $secondHospital);
        $secondAppointment = AppointmentRequest::factory()->create([
            'hospital_id' => $secondHospital->id,
        ]);

        $this->getJson('/api/v1/appointments', [
            'Authorization' => 'Bearer '.$plainTextToken,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstAppointment->id)
            ->assertJsonMissingPath('data.0.name')
            ->assertJsonMissingPath('data.0.phone')
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.reason');

        $this->getJson('/api/v1/appointments/'.$secondAppointment->id, [
            'Authorization' => 'Bearer '.$plainTextToken,
        ])->assertNotFound();

        $this->assertNotNull(TenantApiToken::withoutGlobalScopes()->firstOrFail()->fresh()->last_used_at);
    }

    public function test_admin_can_create_and_revoke_a_one_time_api_token(): void
    {
        $hospital = $this->createHospital('token-admin');
        $admin = $this->createAdmin($hospital);

        $response = $this->actingAs($admin)->post(route('admin.integrations.tokens.store'), [
            'name' => 'Analytics',
        ])->assertRedirect(route('admin.integrations.index'));
        $plainTextToken = $response->getSession()->get('created_api_token');
        $this->assertIsString($plainTextToken);
        $this->assertSame(68, strlen($plainTextToken));

        $token = TenantApiToken::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(hash('sha256', $plainTextToken), $token->token_hash);

        $this->actingAs($admin)
            ->get(route('admin.integrations.index'))
            ->assertOk()
            ->assertSeeText($plainTextToken);
        $this->actingAs($admin)
            ->get(route('admin.integrations.index'))
            ->assertOk()
            ->assertDontSeeText($plainTextToken);

        $this->actingAs($admin)
            ->delete(route('admin.integrations.tokens.destroy', $token->id))
            ->assertRedirect(route('admin.integrations.index'));
        $this->getJson('/api/v1/appointments', [
            'Authorization' => 'Bearer '.$plainTextToken,
        ])->assertUnauthorized();
    }

    public function test_non_admin_cannot_manage_tenant_tokens(): void
    {
        $hospital = $this->createHospital('token-doctor');
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_doctor' => true,
            'role' => 'doctor',
        ]);

        $this->actingAs($doctor)
            ->post(route('admin.integrations.tokens.store'), ['name' => 'No access'])
            ->assertForbidden();
        $this->assertDatabaseCount('tenant_api_tokens', 0);
    }

    public function test_admin_rejects_webhook_urls_that_resolve_to_loopback_addresses(): void
    {
        $hospital = $this->createHospital('webhook-ssrf');
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)
            ->from(route('admin.integrations.index'))
            ->post(route('admin.integrations.webhooks.store'), [
                'url' => 'https://127.0.0.1/hook',
                'events' => ['appointment.created'],
            ])
            ->assertSessionHasErrors('url');
        $this->assertDatabaseCount('webhook_subscriptions', 0);
    }

    public function test_admin_can_create_a_webhook_with_a_one_time_encrypted_secret(): void
    {
        $hospital = $this->createHospital('webhook-admin');
        $admin = $this->createAdmin($hospital);
        $urlGuard = \Mockery::mock(WebhookUrlGuard::class);
        $urlGuard->shouldReceive('resolvePublicAddresses')
            ->once()
            ->with('https://hooks.example.org/appointments')
            ->andReturn(['93.184.216.34']);
        $this->app->instance(WebhookUrlGuard::class, $urlGuard);

        $response = $this->actingAs($admin)
            ->post(route('admin.integrations.webhooks.store'), [
                'url' => 'https://hooks.example.org/appointments',
                'events' => ['appointment.created', 'appointment.updated'],
            ])
            ->assertRedirect(route('admin.integrations.index'));
        $secret = $response->getSession()->get('created_webhook_secret');
        $this->assertIsString($secret);

        $subscription = WebhookSubscription::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($secret, $subscription->secret);
        $this->actingAs($admin)
            ->get(route('admin.integrations.index'))
            ->assertOk()
            ->assertSeeText($secret);
        $this->actingAs($admin)
            ->get(route('admin.integrations.index'))
            ->assertOk()
            ->assertDontSeeText($secret);
    }

    public function test_admin_cannot_disable_another_tenants_webhook(): void
    {
        $firstHospital = $this->createHospital('webhook-owner');
        $admin = $this->createAdmin($firstHospital);
        $secondHospital = $this->createHospital('webhook-other');
        app()->instance('currentHospital', $secondHospital);
        $subscription = $secondHospital->webhookSubscriptions()->create([
            'url' => 'https://hooks.example.org/appointments',
            'secret' => 'whsec_secret',
            'events' => ['appointment.created'],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.integrations.webhooks.destroy', $subscription->id))
            ->assertNotFound();
        $this->assertDatabaseHas('webhook_subscriptions', [
            'id' => $subscription->id,
            'is_active' => true,
        ]);
    }

    public function test_appointment_webhook_delivery_is_signed_and_uses_a_stable_delivery_id(): void
    {
        $hospital = $this->createHospital('webhook-delivery');
        app()->instance('currentHospital', $hospital);
        $subscription = $hospital->webhookSubscriptions()->create([
            'url' => 'https://public.example.org/hooks/appointments',
            'secret' => 'whsec_test-secret',
            'events' => ['appointment.created'],
            'is_active' => true,
        ]);
        $payload = [
            'booking_reference' => 'BK-'.str_repeat('A', 32),
            'status' => 'confirmed',
        ];
        $delivery = WebhookDelivery::query()->create([
            'hospital_id' => $hospital->id,
            'webhook_subscription_id' => $subscription->id,
            'delivery_id' => 'a5d979d2-94a1-48ea-bbd2-fad9922cdd13',
            'event' => 'appointment.created',
            'payload' => $payload,
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);
        $urlGuard = \Mockery::mock(WebhookUrlGuard::class);
        $urlGuard->shouldReceive('resolvePublicAddresses')
            ->once()
            ->with($subscription->url)
            ->andReturn(['93.184.216.34']);
        $urlGuard->shouldReceive('curlResolveEntries')
            ->once()
            ->with($subscription->url, ['93.184.216.34'])
            ->andReturn(['public.example.org:443:93.184.216.34']);
        $this->app->instance(WebhookUrlGuard::class, $urlGuard);
        Http::preventStrayRequests();
        Http::fake([
            'https://public.example.org/*' => Http::response([], 204),
        ]);
        Cache::flush();

        (new DeliverWebhookDelivery($delivery->id))->handle($urlGuard);

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        Http::assertSent(function ($request) use ($delivery, $subscription): bool {
            $expectedSignature = 'sha256='.hash_hmac('sha256', $request->body(), $subscription->secret);

            return $request->header('X-Pearlie-Delivery') === [$delivery->delivery_id]
                && $request->header('X-Pearlie-Signature') === [$expectedSignature]
                && $request->header('X-Pearlie-Event') === [$delivery->event];
        });
    }

    public function test_appointment_observer_queues_a_privacy_limited_tenant_event(): void
    {
        $hospital = $this->createHospital('webhook-observer');
        app()->instance('currentHospital', $hospital);
        $hospital->webhookSubscriptions()->create([
            'url' => 'https://public.example.org/hooks',
            'secret' => 'whsec_test-secret',
            'events' => ['appointment.created'],
            'is_active' => true,
        ]);
        Queue::fake([DeliverWebhookDelivery::class]);

        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Private Name',
            'phone' => '254712345678',
            'email' => 'private@example.org',
            'reason' => 'Private reason',
        ]);

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame($appointment->hospital_id, $delivery->hospital_id);
        $this->assertSame($appointment->booking_reference, $delivery->payload['booking_reference']);
        $this->assertArrayNotHasKey('name', $delivery->payload);
        $this->assertArrayNotHasKey('phone', $delivery->payload);
        $this->assertArrayNotHasKey('email', $delivery->payload);
        $this->assertArrayNotHasKey('reason', $delivery->payload);
        Queue::assertPushed(DeliverWebhookDelivery::class);
    }

    public function test_server_error_keeps_webhook_delivery_pending_for_bounded_retry(): void
    {
        $hospital = $this->createHospital('webhook-retry');
        app()->instance('currentHospital', $hospital);
        $subscription = $hospital->webhookSubscriptions()->create([
            'url' => 'https://public.example.org/hooks',
            'secret' => 'whsec_test-secret',
            'events' => ['appointment.created'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::query()->create([
            'hospital_id' => $hospital->id,
            'webhook_subscription_id' => $subscription->id,
            'delivery_id' => 'dc3a9826-310a-4c1a-85f9-f565f065226e',
            'event' => 'appointment.created',
            'payload' => ['booking_reference' => 'BK-'.str_repeat('B', 32)],
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);
        $urlGuard = \Mockery::mock(WebhookUrlGuard::class);
        $urlGuard->shouldReceive('resolvePublicAddresses')
            ->once()
            ->with($subscription->url)
            ->andReturn(['93.184.216.34']);
        $urlGuard->shouldReceive('curlResolveEntries')
            ->once()
            ->andReturn(['public.example.org:443:93.184.216.34']);
        Http::preventStrayRequests();
        Http::fake([
            'https://public.example.org/*' => Http::response([], 503),
        ]);
        Cache::flush();

        try {
            (new DeliverWebhookDelivery($delivery->id))->handle($urlGuard);
            $this->fail('A retryable response should be thrown so the queue retries the delivery.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Webhook endpoint returned a retryable HTTP status.', $exception->getMessage());
        }

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_PENDING, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(503, $delivery->http_status);
        $this->assertNotNull($delivery->next_attempt_at);
    }

    public function test_migration_repairs_missing_index_on_an_existing_webhook_delivery_table(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropIndex('webhook_deliveries_tenant_lookup');
        });

        $migration = require database_path('migrations/2026_10_25_213134_create_tenant_integration_tables.php');
        $migration->up();

        $this->assertTrue(Schema::hasIndex(
            'webhook_deliveries',
            ['hospital_id', 'webhook_subscription_id', 'created_at'],
        ));
        $this->assertContains(
            'webhook_deliveries_tenant_lookup',
            Schema::getIndexListing('webhook_deliveries'),
        );
    }

    private function createHospital(string $slug): Hospital
    {
        return Hospital::factory()->create([
            'slug' => $slug,
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
    }

    private function createAdmin(Hospital $hospital): User
    {
        app()->instance('currentHospital', $hospital);

        return User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
    }
}
