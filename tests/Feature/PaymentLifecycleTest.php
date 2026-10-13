<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentNotification;
use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\MpesaCallbackInbox;
use App\Models\MpesaPayment;
use App\Models\PaymentEvent;
use App\Models\SlotHold;
use App\Models\User;
use App\Services\DoctorAvailabilityService;
use App\Services\MpesaService;
use App\Services\NotificationService;
use App\Services\PaymentReconciliationService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class PaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_callback_confirms_appointment_and_records_event(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $hospital->update(['settings' => ['auto_confirm_paid_appointments' => true]]);
        $payment->forceFill(['phone' => '0712345678'])->save();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);

        $payload = $this->callbackPayload($payment, 0);
        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $payload)
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $payload)
            ->assertOk()
            ->assertExactJson(['status' => 'already_processed']);

        $this->assertDatabaseCount('mpesa_callback_inbox', 1);
        $this->assertDatabaseHas('mpesa_callback_inbox', [
            'checkout_request_id' => $payment->checkout_request_id,
            'status' => MpesaCallbackInbox::STATUS_PROCESSED,
        ]);

        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CONFIRMED,
            'payment_status' => 'paid',
        ]);
        $this->assertNotNull($appointment->fresh()->paid_at);
        $this->assertNotNull($appointment->fresh()->status_updated_at);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => 'payment_completed',
        ]);
        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $payment->id)->count());
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    public function test_successful_tenant_callback_records_payment_event(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(MpesaPayment::STATUS_INITIATED);
        $hospital->update(['settings' => ['auto_confirm_paid_appointments' => true]]);
        $this->configureAllowlistedProductionCallback($payment, 0);
        $this->freezeTime();
        Queue::fake([SendPaymentNotification::class]);

        $this->withServerVariables(['REMOTE_ADDR' => '196.201.212.10'])
            ->postJson(
                '/api/mpesa/callback/'.$hospital->slug,
                $this->callbackPayload($payment, 0),
            )
            ->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
        ]);
        $this->assertSame(
            1,
            PaymentEvent::query()->where('payment_id', $payment->id)->count(),
        );
        $event = PaymentEvent::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('payment_completed', $event->event);
        $this->assertTrue($event->created_at->between(now()->subSeconds(2), now()->addSeconds(2)));
    }

    public function test_successful_callback_without_auto_confirm_leaves_appointment_pending_and_writes_awaiting_event(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(MpesaPayment::STATUS_INITIATED);
        $hospital->update(['settings' => ['auto_confirm_paid_appointments' => false]]);
        $this->createSlotHold($appointment);
        $this->configureAllowlistedProductionCallback($payment, 0);
        Queue::fake([SendPaymentNotification::class]);

        $this->withServerVariables(['REMOTE_ADDR' => '196.201.212.10'])
            ->postJson(
                '/api/mpesa/callback/'.$hospital->slug,
                $this->callbackPayload($payment, 0),
            )
            ->assertOk();

        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
        ]);
        $this->assertSame(0, SlotHold::query()->where('appointment_request_id', $appointment->id)->count());
        $this->assertSame(
            1,
            PaymentEvent::query()
                ->where('payment_id', $payment->id)
                ->where('event', 'payment_completed_awaiting_confirmation')
                ->count(),
        );
        Queue::assertPushed(SendPaymentNotification::class, fn (SendPaymentNotification $job): bool => (
            $job->outcome === 'payment_completed_awaiting_confirmation'
            && $job->recipientRole === 'patient'
        ));
        Queue::assertPushed(SendPaymentNotification::class, fn (SendPaymentNotification $job): bool => (
            $job->outcome === 'payment_completed_awaiting_confirmation'
            && $job->recipientRole === 'admin'
        ));
    }

    public function test_successful_callback_with_auto_confirm_confirms_appointment(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(MpesaPayment::STATUS_INITIATED);
        $hospital->update(['settings' => ['auto_confirm_paid_appointments' => true]]);
        $this->createSlotHold($appointment);
        $this->configureAllowlistedProductionCallback($payment, 0);
        Queue::fake([SendPaymentNotification::class]);

        $this->withServerVariables(['REMOTE_ADDR' => '196.201.212.10'])
            ->postJson(
                '/api/mpesa/callback/'.$hospital->slug,
                $this->callbackPayload($payment, 0),
            )
            ->assertOk();

        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CONFIRMED,
            'payment_status' => 'paid',
        ]);
        $this->assertSame(0, SlotHold::query()->where('appointment_request_id', $appointment->id)->count());
        $this->assertSame(
            1,
            PaymentEvent::query()
                ->where('payment_id', $payment->id)
                ->where('event', 'payment_completed')
                ->count(),
        );
        Queue::assertPushed(SendPaymentNotification::class, fn (SendPaymentNotification $job): bool => (
            $job->outcome === 'payment_completed'
            && $job->recipientRole === 'patient'
        ));
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    public function test_awaiting_confirmation_notifications_send_patient_message_and_notify_hospital_admin(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        User::factory()->for($hospital, 'hospital')->create([
            'role' => 'hospital_admin',
            'phone' => '254700000001',
        ]);
        $notifications = \Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('sendSms')
            ->once()
            ->with(
                '254712345678',
                'Payment received. Your appointment is being reviewed and you will receive a confirmation shortly.',
            )
            ->andReturn(true);
        $notifications->shouldReceive('sendSms')
            ->once()
            ->with(
                '254700000001',
                sprintf(
                    'Paid appointment #%d is awaiting confirmation. Please review it in the admin dashboard.',
                    $appointment->id,
                ),
            )
            ->andReturn(true);
        $whatsapp = \Mockery::mock(WhatsAppService::class);
        $whatsapp->shouldNotReceive('sendAppointmentConfirmation');

        (new SendPaymentNotification(
            $payment->id,
            $hospital->id,
            'payment_completed_awaiting_confirmation',
        ))->handle($whatsapp, $notifications);
        (new SendPaymentNotification(
            $payment->id,
            $hospital->id,
            'payment_completed_awaiting_confirmation',
            'admin',
        ))->handle($whatsapp, $notifications);
    }

    public function test_failed_tenant_callback_records_payment_event(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(MpesaPayment::STATUS_INITIATED);
        $this->configureAllowlistedProductionCallback($payment, 1037);
        Queue::fake([SendPaymentNotification::class]);

        $this->withServerVariables(['REMOTE_ADDR' => '196.201.212.10'])
            ->postJson(
                '/api/mpesa/callback/'.$hospital->slug,
                $this->callbackPayload($payment, 1037),
            )
            ->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_FAILED,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CANCELLED,
        ]);
        $this->assertSame(
            1,
            PaymentEvent::query()->where('payment_id', $payment->id)->count(),
        );
        $event = PaymentEvent::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('payment_failed', $event->event);
    }

    public function test_successful_callback_does_not_confirm_an_expired_booking_after_late_payment(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $hospital->update(['settings' => ['auto_confirm_paid_appointments' => true]]);
        $appointment->forceFill([
            'status' => AppointmentRequest::STATUS_EXPIRED,
            'status_updated_at' => now()->subMinute(),
        ])->save();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $this->callbackPayload($payment, 0))
            ->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_EXPIRED,
            'payment_status' => 'refund_pending',
        ]);
        $this->assertNotNull($appointment->fresh()->paid_at);
        Queue::assertPushed(SendPaymentNotification::class, 2);
    }

    public function test_failed_callback_cancels_appointment_without_marking_it_paid(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $this->callbackPayload($payment, 1037))
            ->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_FAILED,
            'result_code' => 1037,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CANCELLED,
            'payment_status' => 'unpaid',
            'paid_at' => null,
        ]);
        $this->assertNotNull($appointment->fresh()->status_updated_at);
        Queue::assertPushed(SendPaymentNotification::class, fn (SendPaymentNotification $job): bool => $job->outcome === 'payment_failed');
    }

    public function test_callback_on_cancelled_payment_is_ignored(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(
            MpesaPayment::STATUS_CANCELLED,
        );
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Log::spy();
        Queue::fake([SendPaymentNotification::class]);

        $this->postJson(
            '/api/mpesa/callback/'.$hospital->slug,
            $this->callbackPayload($payment, 0),
        )->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_CANCELLED,
            'callback_payload' => null,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'payment_status' => 'pending',
            'mpesa_result_code' => null,
            'mpesa_receipt' => null,
        ]);
        $this->assertDatabaseMissing('payment_events', [
            'payment_id' => $payment->id,
        ]);
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Ignoring an M-Pesa callback for a non-pending payment.'
                && $context['checkout_request_id'] === $payment->checkout_request_id
                && $context['payment_status'] === MpesaPayment::STATUS_CANCELLED);
    }

    public function test_user_cancellation_marks_payment_failed_and_cancels_appointment(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $this->callbackPayload($payment, 1032))
            ->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_FAILED,
            'result_code' => 1032,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CANCELLED,
            'payment_status' => 'unpaid',
        ]);
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    public function test_insufficient_funds_marks_payment_failed_and_cancels_appointment(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $this->callbackPayload($payment, 1))
            ->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_FAILED,
            'result_code' => 1,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CANCELLED,
            'payment_status' => 'unpaid',
        ]);
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    public function test_callback_rejects_amount_mismatch_without_completing_payment(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
        $payload = $this->callbackPayload($payment, 0);
        $payload['Body']['stkCallback']['CallbackMetadata']['Item'][0]['Value'] = 1;

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $payload)->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_FAILED,
            'result_code' => 0,
            'result_description' => 'amount_mismatch',
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CANCELLED,
            'payment_status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => 'payment_failed',
        ]);
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    public function test_callback_rejects_phone_mismatch_without_completing_payment(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
        $payload = $this->callbackPayload($payment, 0);
        $payload['Body']['stkCallback']['CallbackMetadata']['Item'][2]['Value'] = 254799999999;

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $payload)->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_FAILED,
            'result_code' => 0,
            'result_description' => 'phone_mismatch',
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CANCELLED,
            'payment_status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => 'payment_failed',
        ]);
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    public function test_reconciliation_confirms_a_stale_payment_with_successful_provider_result(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $hospital->update(['settings' => ['auto_confirm_paid_appointments' => true]]);
        $payment->forceFill(['created_at' => now()->subSeconds(120)])->save();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => $payment->checkout_request_id,
                'ResultCode' => '0',
                'ResultDesc' => 'Payment completed successfully.',
                'TransactionReceipt' => 'RECONCILED001',
            ]),
        ]);
        Queue::fake([SendPaymentNotification::class]);

        $this->assertSame(1, app(PaymentReconciliationService::class)->reconcile());

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
            'mpesa_receipt' => 'RECONCILED001',
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CONFIRMED,
            'payment_status' => 'paid',
        ]);
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    public function test_reconciliation_processes_payments_for_each_hospital_and_restores_the_binding(): void
    {
        [$firstHospital, $firstAppointment, $firstPayment] = $this->createPaymentScenario();
        $firstHospital->update(['settings' => ['auto_confirm_paid_appointments' => true]]);
        $firstPayment->forceFill(['created_at' => now()->subSeconds(120)])->save();
        $secondHospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'mpesa_consumer_key' => 'second-test-consumer',
            'mpesa_consumer_secret' => 'second-test-secret',
            'mpesa_passkey' => 'second-test-passkey',
            'mpesa_shortcode' => '174379',
            'settings' => ['auto_confirm_paid_appointments' => true],
        ]);
        app()->instance('currentHospital', $secondHospital);
        $secondDoctor = User::factory()->for($secondHospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $secondAppointment = AppointmentRequest::factory()
            ->for($secondHospital)
            ->for($secondDoctor, 'doctor')
            ->create(['payment_amount' => 500]);
        $secondPayment = MpesaPayment::factory()
            ->for($secondAppointment, 'appointment')
            ->create([
                'amount' => 500,
                'phone' => '254712345678',
                'created_at' => now()->subSeconds(120),
            ]);
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => fn ($request) => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => $request['CheckoutRequestID'],
                'ResultCode' => '0',
                'ResultDesc' => 'Payment completed successfully.',
                'TransactionReceipt' => 'RECONCILED-'.$request['CheckoutRequestID'],
            ]),
        ]);
        Queue::fake([SendPaymentNotification::class]);
        app()->instance('currentHospital', $firstHospital);

        $this->assertSame(2, app(PaymentReconciliationService::class)->reconcile());
        $this->assertSame($firstHospital->id, hospital()?->id);
        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $firstPayment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $secondPayment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $firstAppointment->id,
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $secondAppointment->id,
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        Queue::assertPushed(SendPaymentNotification::class, 2);
    }

    public function test_reconciliation_leaves_a_pending_payment_unresolved_after_a_transient_provider_error(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $payment->forceFill(['created_at' => now()->subMinutes(6)])->save();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::failedConnection(),
        ]);
        Queue::fake([SendPaymentNotification::class]);

        $this->assertSame(0, app(PaymentReconciliationService::class)->reconcile());

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'pending',
        ]);
        $this->assertDatabaseMissing('payment_events', [
            'payment_id' => $payment->id,
            'event' => 'payment_timeout',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_reconciliation_leaves_an_inconclusive_payment_pending_after_thirty_minutes(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $payment->forceFill(['created_at' => now()->subMinutes(31)])->save();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => $payment->checkout_request_id,
                'ResultDesc' => 'The request is still being processed.',
            ]),
        ]);
        Queue::fake([SendPaymentNotification::class]);

        $this->assertSame(0, app(PaymentReconciliationService::class)->reconcile());

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'pending',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_reconciliation_times_out_only_after_provider_confirms_timeout(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $payment->forceFill(['created_at' => now()->subMinutes(31)])->save();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => $payment->checkout_request_id,
                'ResultCode' => 1037,
                'ResultDesc' => 'The request timed out.',
            ]),
        ]);
        Queue::fake([SendPaymentNotification::class]);

        $this->assertSame(1, app(PaymentReconciliationService::class)->reconcile());

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_TIMEOUT,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CANCELLED,
            'payment_status' => 'unpaid',
        ]);
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    public function test_status_endpoint_does_not_expose_another_hospitals_payment(): void
    {
        [$firstHospital] = $this->createPaymentScenario();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
        $firstHospitalAdmin = User::factory()->for($firstHospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        $secondHospital = Hospital::factory()->create(['subscription_plan' => 'enterprise']);
        app()->instance('currentHospital', $secondHospital);
        $secondDoctor = User::factory()->for($secondHospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $secondAppointment = AppointmentRequest::factory()
            ->for($secondHospital)
            ->for($secondDoctor, 'doctor')
            ->create();
        $payment = MpesaPayment::factory()
            ->for($secondAppointment, 'appointment')
            ->create();

        $this->actingAs($firstHospitalAdmin)
            ->getJson('/api/payments/'.$payment->id.'/status?hospital='.$firstHospital->slug)
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_guest_cannot_read_payment_status_or_receipt(): void
    {
        [$hospital, , $payment] = $this->createPaymentScenario(MpesaPayment::STATUS_COMPLETED);
        $payment->forceFill(['mpesa_receipt' => 'PRIVATE-RECEIPT'])->save();

        $this->getJson('/api/payments/'.$payment->id.'/status?hospital='.$hospital->slug)
            ->assertUnauthorized()
            ->assertJsonMissingPath('data')
            ->assertDontSee('PRIVATE-RECEIPT');
    }

    public function test_non_admin_tenant_user_cannot_read_payment_status(): void
    {
        [$hospital, , $payment] = $this->createPaymentScenario();
        $payment->forceFill(['mpesa_receipt' => 'PRIVATE-RECEIPT'])->save();
        $patient = User::factory()->for($hospital, 'hospital')->create([
            'role' => 'patient',
        ]);

        $this->actingAs($patient)
            ->getJson('/api/payments/'.$payment->id.'/status?hospital='.$hospital->slug)
            ->assertForbidden()
            ->assertJsonMissingPath('data')
            ->assertDontSee('PRIVATE-RECEIPT');
    }

    public function test_wrong_tenant_admin_cannot_enumerate_payment_ids(): void
    {
        $firstHospital = Hospital::factory()->create(['subscription_plan' => 'enterprise']);
        $secondHospital = Hospital::factory()->create(['subscription_plan' => 'enterprise']);
        $requestingHospital = Hospital::factory()->create(['subscription_plan' => 'enterprise']);
        $firstAdmin = User::factory()->for($requestingHospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        app()->instance('currentHospital', $firstHospital);
        $firstAppointment = AppointmentRequest::factory()->for($firstHospital)->create();
        $firstPayment = MpesaPayment::factory()->for($firstAppointment, 'appointment')->create();

        app()->instance('currentHospital', $secondHospital);
        $secondAppointment = AppointmentRequest::factory()->for($secondHospital)->create();
        $secondPayment = MpesaPayment::factory()->for($secondAppointment, 'appointment')->create();
        app()->forgetInstance('currentHospital');

        foreach (range(1, $secondPayment->id + 1) as $paymentId) {
            $this->actingAs($firstAdmin)
                ->getJson('/api/payments/'.$paymentId.'/status?hospital='.$requestingHospital->slug)
                ->assertNotFound()
                ->assertJsonMissingPath('data');
        }

        $this->assertModelExists($firstPayment);
        $this->assertModelExists($secondPayment);
    }

    public function test_status_endpoint_returns_the_required_payment_fields(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/payments/'.$payment->id.'/status?hospital='.$hospital->slug)
            ->assertOk()
            ->assertJsonPath('data.payment_id', $payment->id)
            ->assertJsonPath('data.status', MpesaPayment::STATUS_PENDING)
            ->assertJsonPath('data.appointment_id', $appointment->id)
            ->assertJsonPath('data.appointment_status', AppointmentRequest::STATUS_PENDING)
            ->assertJsonPath('data.receipt', null)
            ->assertJsonPath('data.message', 'Payment is awaiting confirmation.');

        Queue::assertNothingPushed();
    }

    public function test_unassigned_active_slot_hold_makes_its_slot_unavailable(): void
    {
        [$hospital] = $this->createPaymentScenario();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
        $doctor = User::factory()->for($hospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $date = now()->addDay()->startOfDay();
        DoctorAvailability::factory()->for($doctor, 'doctor')->create([
            'day_of_week' => $date->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
        ]);
        SlotHold::query()->create([
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_start_at' => $date->copy()->setTime(9, 0),
            'slot_end_at' => $date->copy()->setTime(9, 30),
            'expires_at' => now()->addMinutes(5),
        ]);

        $slots = app(DoctorAvailabilityService::class)->getAvailableSlots(
            $doctor->id,
            $date->toDateString(),
        );

        $this->assertFalse($slots['09:00']);
        Queue::assertNothingPushed();
    }

    public function test_slot_contention_does_not_create_a_second_booking(): void
    {
        [$hospital] = $this->createPaymentScenario();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
        $doctor = User::factory()->for($hospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $date = now()->addDays(2)->startOfDay();
        DoctorAvailability::factory()->for($doctor, 'doctor')->create([
            'day_of_week' => $date->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
        ]);
        $patient = [
            'name' => 'Test Patient',
            'phone' => '254712345678',
            'reason' => 'Consultation',
            'raw_message' => 'Book an appointment.',
            'session_id' => 'first-booking',
        ];

        $firstAppointment = app(DoctorAvailabilityService::class)->bookAppointment(
            $date->toDateString(),
            '09:00',
            $patient,
            $doctor->id,
        );

        $secondPatient = array_merge($patient, ['session_id' => 'second-booking']);
        $secondAppointment = app(DoctorAvailabilityService::class)->bookAppointment(
            $date->toDateString(),
            '09:00',
            $secondPatient,
            $doctor->id,
        );

        $this->assertNotNull($firstAppointment);
        $this->assertNull($secondAppointment);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $firstAppointment->id,
            'doctor_id' => $doctor->id,
            'status' => AppointmentRequest::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('slot_holds', [
            'appointment_request_id' => $firstAppointment->id,
            'doctor_id' => $doctor->id,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_cleanup_command_deletes_expired_slot_holds_across_tenants(): void
    {
        [$hospital, $appointment] = $this->createPaymentScenario();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
        $doctor = User::factory()->for($hospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $firstHold = SlotHold::query()->create([
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'appointment_request_id' => $appointment->id,
            'slot_start_at' => now()->addDay(),
            'slot_end_at' => now()->addDay()->addMinutes(30),
            'expires_at' => now()->subMinute(),
        ]);
        $secondHospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
        ]);
        app()->instance('currentHospital', $secondHospital);
        $secondDoctor = User::factory()->for($secondHospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $secondHold = SlotHold::query()->create([
            'hospital_id' => $secondHospital->id,
            'doctor_id' => $secondDoctor->id,
            'slot_start_at' => now()->addDay(),
            'slot_end_at' => now()->addDay()->addMinutes(30),
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('app:cleanup-slot-holds')->assertExitCode(0);

        $this->assertDatabaseMissing('slot_holds', ['id' => $firstHold->id]);
        $this->assertDatabaseMissing('slot_holds', ['id' => $secondHold->id]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_EXPIRED,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_failed_stk_initiation_cancels_the_booking_and_releases_its_slot_hold(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $payment->delete();
        $appointment->forceFill([
            'slot_start_time' => '09:00',
            'slot_end_time' => '09:30',
        ])->save();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '1',
                'ResponseDescription' => 'Request could not be processed.',
            ]),
        ]);

        try {
            app(MpesaService::class)->stkPush(
                '0712345678',
                500,
                'APT-'.$appointment->id,
                'Appointment deposit',
                $appointment->id,
            );
            $this->fail('The failed STK request should throw.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('appointment_requests', [
                'id' => $appointment->id,
                'status' => AppointmentRequest::STATUS_CANCELLED,
                'payment_status' => 'unpaid',
            ]);
            $this->assertDatabaseMissing('slot_holds', [
                'appointment_request_id' => $appointment->id,
            ]);
            Queue::assertNothingPushed();
        }
    }

    /**
     * @return array{Hospital, AppointmentRequest, MpesaPayment}
     */
    private function createPaymentScenario(string $paymentStatus = MpesaPayment::STATUS_PENDING): array
    {
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'mpesa_consumer_key' => 'test-consumer',
            'mpesa_consumer_secret' => 'test-secret',
            'mpesa_passkey' => 'test-passkey',
            'mpesa_shortcode' => '174379',
        ]);
        app()->instance('currentHospital', $hospital);
        $doctor = User::factory()->for($hospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $appointment = AppointmentRequest::factory()
            ->for($hospital)
            ->for($doctor, 'doctor')
            ->create([
                'mpesa_phone' => '254712345678',
                'payment_amount' => 500,
            ]);
        $payment = MpesaPayment::factory()
            ->for($appointment, 'appointment')
            ->create([
                'amount' => 500,
                'phone' => '254712345678',
                'status' => $paymentStatus,
            ]);

        return [$hospital, $appointment, $payment];
    }

    private function createSlotHold(AppointmentRequest $appointment): SlotHold
    {
        $slotStart = now()->addDay()->setTime(9, 0);

        return SlotHold::query()->create([
            'hospital_id' => $appointment->hospital_id,
            'doctor_id' => $appointment->doctor_id,
            'appointment_request_id' => $appointment->id,
            'slot_start_at' => $slotStart,
            'slot_end_at' => $slotStart->copy()->addMinutes(30),
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    private function configureLocalSandbox(): void
    {
        app()->detectEnvironment(static fn (): string => 'local');
        config([
            'mpesa.environment' => 'sandbox',
            'mpesa.skip_callback_verification_in_local' => true,
            'mpesa.consumer_key' => 'test-consumer',
            'mpesa.consumer_secret' => 'test-secret',
            'mpesa.passkey' => 'test-passkey',
            'mpesa.shortcode' => '174379',
            'mpesa.timeout' => 5,
        ]);
    }

    private function configureAllowlistedProductionCallback(MpesaPayment $payment, int $resultCode): void
    {
        app()->detectEnvironment(static fn (): string => 'production');
        config([
            'mpesa.environment' => 'sandbox',
            'mpesa.skip_callback_verification_in_local' => false,
            'mpesa.consumer_key' => 'test-consumer',
            'mpesa.consumer_secret' => 'test-secret',
            'mpesa.passkey' => 'test-passkey',
            'mpesa.shortcode' => '174379',
            'mpesa.timeout' => 5,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => $payment->checkout_request_id,
                'ResultCode' => $resultCode,
                'ResultDesc' => $resultCode === 0
                    ? 'The service request is processed successfully.'
                    : 'The request was cancelled.',
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackPayload(MpesaPayment $payment, int $resultCode): array
    {
        return [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => $payment->merchant_request_id,
                    'CheckoutRequestID' => $payment->checkout_request_id,
                    'ResultCode' => $resultCode,
                    'ResultDesc' => $resultCode === 0
                        ? 'The service request is processed successfully.'
                        : 'The request was cancelled.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => $payment->amount],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'TEST123'],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];
    }
}
