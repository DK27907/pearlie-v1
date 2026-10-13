<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentNotification;
use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaCallbackInbox;
use App\Models\MpesaPayment;
use App\Services\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MpesaCallbackVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(
            'currentHospital',
            Hospital::query()->where('slug', 'pearl')->firstOrFail(),
        );
    }

    public function test_sandbox_local_callback_skips_verification(): void
    {
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-local-success',
        ]);

        $this->postJson('/api/mpesa/callback', $this->makeCallback('checkout-local-success'))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
            'result_code' => 0,
            'mpesa_receipt' => 'TEST123ABC',
        ]);
    }

    public function test_production_callback_requires_verification(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');
        $this->configureDaraja('production');
        $this->fakeProviderResult('production', 'checkout-production', 1032);
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-production',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '196.201.212.10'])
            ->postJson('/api/mpesa/callback', $this->makeCallback('checkout-production'))
            ->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
        Http::assertSent(fn ($request): bool => str_ends_with(
            $request->url(),
            '/mpesa/stkpushquery/v1/query',
        ));
    }

    public function test_production_mpesa_environment_verifies_in_local_app(): void
    {
        app()->detectEnvironment(static fn (): string => 'local');
        $this->configureDaraja('production');
        $this->fakeProviderResult('production', 'checkout-production-local', 1032);
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-production-local',
        ]);

        $this->postJson(
            '/api/mpesa/callback',
            $this->makeCallback('checkout-production-local'),
        )->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
        Http::assertSent(fn ($request): bool => str_ends_with(
            $request->url(),
            '/mpesa/stkpushquery/v1/query',
        ));
    }

    public function test_production_app_environment_verifies_sandbox_callbacks(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');
        $this->configureDaraja('sandbox');
        $this->fakeProviderResult('sandbox', 'checkout-sandbox-production-app', 1032);
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-sandbox-production-app',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '196.201.212.10'])
            ->postJson(
                '/api/mpesa/callback',
                $this->makeCallback('checkout-sandbox-production-app'),
            )->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
        Http::assertSent(fn ($request): bool => str_ends_with(
            $request->url(),
            '/mpesa/stkpushquery/v1/query',
        ));
    }

    public function test_sandbox_callback_verifies_when_app_environment_is_not_local(): void
    {
        $this->configureDaraja('sandbox');
        $this->fakeProviderResult('sandbox', 'checkout-testing', 1032);
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-testing',
        ]);

        $this->postJson('/api/mpesa/callback', $this->makeCallback('checkout-testing'))
            ->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
        Http::assertSent(fn ($request): bool => str_ends_with(
            $request->url(),
            '/mpesa/stkpushquery/v1/query',
        ));
    }

    public function test_local_sandbox_verifies_when_skip_flag_is_disabled(): void
    {
        app()->detectEnvironment(static fn (): string => 'local');
        $this->configureDaraja('sandbox');
        config(['mpesa.skip_callback_verification_in_local' => false]);
        $this->fakeProviderResult('sandbox', 'checkout-verification-enabled', 1032);
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-verification-enabled',
        ]);

        $this->postJson(
            '/api/mpesa/callback',
            $this->makeCallback('checkout-verification-enabled'),
        )->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
        Http::assertSent(fn ($request): bool => str_ends_with(
            $request->url(),
            '/mpesa/stkpushquery/v1/query',
        ));
    }

    public function test_callback_for_unknown_checkout_id_is_logged(): void
    {
        Http::preventStrayRequests();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')
            ->once()
            ->with('Received an M-Pesa callback.', Mockery::on(
                fn (array $context): bool => $context['checkout_request_id'] === 'checkout-not-found'
                    && $context['result_code'] === 0
                    && ! array_key_exists('payload', $context),
            ));
        Log::shouldReceive('warning')
            ->once()
            ->with('M-Pesa callback for unknown checkout_request_id', [
                'checkout_request_id' => 'checkout-not-found',
            ]);

        $this->postJson('/api/mpesa/callback', $this->makeCallback('checkout-not-found'))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function test_failed_result_code_marks_payment_failed_and_appointment_unpaid(): void
    {
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        $appointment = $this->createAppointment();
        $payment = MpesaPayment::factory()->for($appointment, 'appointment')->create([
            'checkout_request_id' => 'checkout-local-failed',
        ]);

        $this->postJson('/api/mpesa/callback', $this->makeCallback(
            'checkout-local-failed',
            resultCode: 1032,
            resultDescription: 'Request cancelled by user.',
        ))->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_FAILED,
            'result_code' => 1032,
            'result_description' => 'Request cancelled by user.',
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'payment_status' => 'unpaid',
            'status' => AppointmentRequest::STATUS_CANCELLED,
        ]);
    }

    public function test_successful_callback_marks_appointment_paid_and_confirms_it(): void
    {
        $this->configureLocalSandbox(autoConfirm: true);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'message-test']]]),
        ]);
        $appointment = $this->createAppointment();
        $payment = MpesaPayment::factory()->for($appointment, 'appointment')->create([
            'checkout_request_id' => 'checkout-appointment-paid',
        ]);

        $this->postJson('/api/mpesa/callback', $this->makeCallback('checkout-appointment-paid'))
            ->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
            'amount' => 500,
            'phone' => '254712345678',
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'payment_status' => 'paid',
            'mpesa_receipt' => 'TEST123ABC',
            'mpesa_phone' => '0712345678',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        $this->assertNotNull($appointment->fresh()->paid_at);
    }

    public function test_legacy_callback_preserves_the_appointment_hospital_without_tenant_context(): void
    {
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
        $hospital = Hospital::query()->create([
            'name' => 'Callback Tenant',
            'slug' => 'callback-tenant',
            'subscription_plan' => 'professional',
            'subscription_status' => 'active',
            'is_active' => true,
            'settings' => ['auto_confirm_paid_appointments' => true],
        ]);
        app()->instance('currentHospital', $hospital);

        $appointment = AppointmentRequest::query()->create([
            'session_id' => 'legacy-callback-tenant',
            'name' => 'Jane Doe',
            'phone' => '0712345678',
            'mpesa_phone' => '0712345678',
            'mpesa_checkout_request_id' => 'checkout-legacy-tenant',
            'preferred_date' => now()->addDay()->toDateString(),
            'reason' => 'Consultation',
            'raw_message' => 'Book an appointment',
            'status' => AppointmentRequest::STATUS_PENDING,
            'booking_fee' => 500,
            'payment_amount' => 500,
            'payment_status' => 'pending',
        ]);
        app()->instance('currentHospital', null);

        $payment = app(MpesaService::class)->handleCallback(
            $this->makeCallback('checkout-legacy-tenant'),
        );

        $this->assertNotNull($payment);
        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'hospital_id' => $hospital->id,
            'appointment_request_id' => $appointment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('payment_events', [
            'hospital_id' => $hospital->id,
            'payment_id' => $payment->id,
            'event' => 'payment_completed',
        ]);

        $replayedPayment = app(MpesaService::class)->handleCallback(
            $this->makeCallback('checkout-legacy-tenant'),
        );

        $this->assertSame($payment->id, $replayedPayment?->id);
        $this->assertDatabaseCount('mpesa_payments', 1);
        $this->assertDatabaseCount('payment_events', 1);
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    public function test_callback_endpoint_processes_a_payment_for_another_hospital(): void
    {
        $this->configureLocalSandbox(autoConfirm: true);
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
        $defaultHospital = hospital();
        $paymentHospital = Hospital::factory()->create([
            'settings' => ['auto_confirm_paid_appointments' => true],
        ]);
        app()->instance('currentHospital', $paymentHospital);
        $appointment = AppointmentRequest::factory()->create([
            'phone' => '0712345678',
            'mpesa_phone' => '0712345678',
            'payment_amount' => 500,
        ]);
        $payment = MpesaPayment::factory()
            ->for($appointment, 'appointment')
            ->create(['checkout_request_id' => 'checkout-cross-tenant']);
        app()->instance('currentHospital', $defaultHospital);

        $this->postJson('/api/mpesa/callback', $this->makeCallback('checkout-cross-tenant'))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'hospital_id' => $paymentHospital->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
            'mpesa_receipt' => 'TEST123ABC',
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'hospital_id' => $paymentHospital->id,
            'payment_status' => 'paid',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'hospital_id' => $paymentHospital->id,
            'event' => 'payment_completed',
        ]);
        $this->assertSame(
            1,
            DB::table('payment_events')->where('payment_id', $payment->id)->count(),
        );
        $this->assertSame($defaultHospital->id, hospital()->id);
        Queue::assertPushed(
            SendPaymentNotification::class,
            fn (SendPaymentNotification $job): bool => $job->paymentId === $payment->id
                && $job->hospitalId === $paymentHospital->id,
        );

        $this->postJson('/api/mpesa/callback', $this->makeCallback('checkout-cross-tenant'))
            ->assertOk()
            ->assertExactJson(['status' => 'already_processed']);

        $this->assertSame(
            1,
            DB::table('payment_events')->where('payment_id', $payment->id)->count(),
        );
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    public function test_unknown_callback_is_not_cached_as_processed(): void
    {
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        $callback = $this->makeCallback('checkout-unknown-retry');

        $this->postJson('/api/mpesa/callback', $callback)
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $this->postJson('/api/mpesa/callback', $callback)
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function test_successful_callback_without_receipt_still_completes_payment(): void
    {
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-without-receipt',
        ]);
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')
            ->once()
            ->with('Received an M-Pesa callback.', Mockery::on(
                fn (array $context): bool => $context['checkout_request_id'] === 'checkout-without-receipt'
                    && $context['result_code'] === 0
                    && ! array_key_exists('payload', $context),
            ));
        Log::shouldReceive('info')
            ->once()
            ->with('M-Pesa handleCallback: verification', [
                'is_local_sandbox' => true,
                'checkout_request_id' => 'checkout-without-receipt',
            ]);
        Log::shouldReceive('warning')
            ->once()
            ->with('M-Pesa verification SKIPPED (local sandbox only)', [
                'checkout_request_id' => 'checkout-without-receipt',
            ]);
        Log::shouldReceive('warning')
            ->once()
            ->with('M-Pesa callback did not include an M-Pesa receipt number.', [
                'checkout_request_id' => 'checkout-without-receipt',
            ]);

        $this->postJson('/api/mpesa/callback', $this->makeCallback(
            'checkout-without-receipt',
            receipt: null,
        ))->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
            'mpesa_receipt' => null,
        ]);
    }

    public function test_callback_returns_200_when_verification_is_unavailable(): void
    {
        $this->configureDaraja('production');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'errorCode' => '500.001.1001',
            ], 500),
        ]);
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-query-unavailable',
        ]);

        $this->postJson('/api/mpesa/callback', $this->makeCallback('checkout-query-unavailable'))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
    }

    public function test_callback_controller_returns_200_on_exception(): void
    {
        $mpesa = Mockery::mock(MpesaService::class);
        $mpesa->shouldReceive('handleCallback')
            ->once()
            ->andReturnUsing(function (array $payload): ?MpesaPayment {
                $inbox = MpesaCallbackInbox::query()
                    ->where('checkout_request_id', 'checkout-controller-error')
                    ->firstOrFail();

                $this->assertSame(MpesaCallbackInbox::STATUS_PROCESSING, $inbox->status);
                $this->assertSame($payload, $inbox->payload);

                throw new RuntimeException('Synthetic callback failure.');
            });
        $this->app->instance(MpesaService::class, $mpesa);
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')
            ->once()
            ->with('M-Pesa callback inbox processing threw an exception.', Mockery::on(
                fn (array $context): bool => $context['checkout_request_id'] === 'checkout-controller-error'
                    && $context['exception'] instanceof RuntimeException
                    && $context['exception']->getMessage() === 'Synthetic callback failure.',
            ));

        $this->postJson('/api/mpesa/callback', $this->makeCallback('checkout-controller-error'))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $inbox = MpesaCallbackInbox::query()
            ->where('checkout_request_id', 'checkout-controller-error')
            ->firstOrFail();
        $this->assertSame(MpesaCallbackInbox::STATUS_RETRY, $inbox->status);
        $this->assertSame(1, $inbox->attempts);
    }

    public function test_tenant_callback_route_cannot_match_another_hospitals_payment(): void
    {
        $otherHospital = Hospital::factory()->create(['slug' => 'callback-other-tenant']);
        app()->instance('currentHospital', $otherHospital);
        $payment = MpesaPayment::factory()->create([
            'hospital_id' => $otherHospital->id,
            'checkout_request_id' => 'checkout-other-tenant',
        ]);
        app()->instance(
            'currentHospital',
            Hospital::query()->where('slug', 'pearl')->firstOrFail(),
        );
        $this->configureLocalSandbox();
        Http::preventStrayRequests();

        $this->postJson(
            '/api/mpesa/callback/pearl',
            $this->makeCallback('checkout-other-tenant'),
        )->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'hospital_id' => $otherHospital->id,
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('mpesa_callback_inbox', [
            'checkout_request_id' => 'checkout-other-tenant',
            'hospital_slug' => 'pearl',
            'status' => MpesaCallbackInbox::STATUS_RETRY,
        ]);
    }

    public function test_callback_inbox_command_retries_due_entry_with_a_bounded_batch(): void
    {
        $payload = $this->makeCallback('checkout-due-retry');
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-due-retry',
        ]);
        $inbox = MpesaCallbackInbox::query()->create([
            'checkout_request_id' => 'checkout-due-retry',
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'payload' => $payload,
            'status' => MpesaCallbackInbox::STATUS_RETRY,
            'attempts' => 1,
            'available_at' => now()->subMinute(),
        ]);

        $mpesa = Mockery::mock(MpesaService::class);
        $mpesa->shouldReceive('handleCallback')
            ->once()
            ->with($payload, null)
            ->andReturn($payment);
        $this->app->instance(MpesaService::class, $mpesa);

        $this->artisan('app:process-mpesa-callback-inbox', ['--limit' => 1])
            ->assertExitCode(0);

        $this->assertDatabaseHas('mpesa_callback_inbox', [
            'id' => $inbox->id,
            'status' => MpesaCallbackInbox::STATUS_PROCESSED,
            'attempts' => 2,
        ]);
    }

    public function test_local_simulator_applies_callback_to_existing_payment(): void
    {
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-manual-simulation',
        ]);

        $this->artisan('mpesa:simulate-callback', [
            'checkout_request_id' => 'checkout-manual-simulation',
            '--receipt' => 'MANUAL001',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
            'mpesa_receipt' => 'MANUAL001',
        ]);
    }

    public function test_auto_confirm_setting_confirms_paid_appointment(): void
    {
        $this->configureLocalSandbox(autoConfirm: true);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'message-test']]]),
        ]);
        $appointment = $this->createAppointment();
        $payment = MpesaPayment::factory()->for($appointment, 'appointment')->create([
            'checkout_request_id' => 'checkout-auto-confirm',
        ]);

        $this->postJson('/api/mpesa/callback', $this->makeCallback('checkout-auto-confirm'))
            ->assertOk();

        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'payment_status' => 'paid',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
        ]);
    }

    private function configureLocalSandbox(bool $autoConfirm = false): void
    {
        app()->detectEnvironment(static fn (): string => 'local');
        $this->configureDaraja('sandbox');
        config([
            'mpesa.skip_callback_verification_in_local' => true,
        ]);

        $hospital = hospital();
        $settings = $hospital->settings ?? [];
        $settings['auto_confirm_paid_appointments'] = $autoConfirm;
        $hospital->update(['settings' => $settings]);
    }

    private function configureDaraja(string $environment): void
    {
        config([
            'mpesa.environment' => $environment,
            'mpesa.consumer_key' => 'test-consumer',
            'mpesa.consumer_secret' => 'test-secret',
            'mpesa.passkey' => 'test-passkey',
            'mpesa.shortcode' => '174379',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
            'mpesa.timeout' => 5,
        ]);
    }

    private function fakeProviderResult(string $environment, string $checkoutRequestId, int $resultCode): void
    {
        $baseUrl = $environment === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';

        Http::preventStrayRequests();
        Http::fake([
            $baseUrl.'/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            $baseUrl.'/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => $checkoutRequestId,
                'ResultCode' => $resultCode,
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeCallback(
        string $checkoutRequestId,
        int $resultCode = 0,
        ?string $receipt = 'TEST123ABC',
        string $resultDescription = 'The service request is processed successfully.',
    ): array {
        $items = [
            ['Name' => 'Amount', 'Value' => 500],
            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
        ];

        if ($receipt !== null) {
            $items[] = ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt];
        }

        return [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant-test',
                    'CheckoutRequestID' => $checkoutRequestId,
                    'ResultCode' => $resultCode,
                    'ResultDesc' => $resultDescription,
                    'CallbackMetadata' => ['Item' => $items],
                ],
            ],
        ];
    }

    private function createAppointment(): AppointmentRequest
    {
        return AppointmentRequest::query()->create([
            'session_id' => 'mpesa-callback-test',
            'name' => 'Jane Doe',
            'phone' => '0712345678',
            'mpesa_phone' => '0712345678',
            'preferred_date' => now()->addDay()->toDateString(),
            'reason' => 'Consultation',
            'raw_message' => 'Book an appointment',
            'status' => AppointmentRequest::STATUS_PENDING,
            'booking_fee' => 500,
            'payment_status' => 'pending',
        ]);
    }
}
