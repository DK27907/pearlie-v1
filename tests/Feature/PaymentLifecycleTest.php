<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentNotification;
use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\PaymentEvent;
use App\Models\SlotHold;
use App\Models\User;
use App\Services\DoctorAvailabilityService;
use App\Services\MpesaService;
use App\Services\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class PaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_callback_confirms_appointment_and_records_event(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $payment->forceFill(['phone' => '0712345678'])->save();
        $this->configureLocalSandbox();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);

        $payload = $this->callbackPayload($payment, 0);
        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $payload)->assertOk();
        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $payload)->assertOk();

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

    public function test_successful_callback_confirms_an_expired_booking_after_late_payment(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
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
            'status' => AppointmentRequest::STATUS_CONFIRMED,
            'payment_status' => 'paid',
        ]);
        $this->assertNotNull($appointment->fresh()->status_updated_at);
        Queue::assertPushed(SendPaymentNotification::class, 1);
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
        $firstPayment->forceFill(['created_at' => now()->subSeconds(120)])->save();
        $secondHospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'mpesa_consumer_key' => 'second-test-consumer',
            'mpesa_consumer_secret' => 'second-test-secret',
            'mpesa_passkey' => 'second-test-passkey',
            'mpesa_shortcode' => '174379',
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

    public function test_reconciliation_times_out_a_pending_payment_after_five_minutes(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        $payment->forceFill(['created_at' => now()->subMinutes(6)])->save();
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
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => 'payment_timeout',
        ]);
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    public function test_status_endpoint_does_not_expose_another_hospitals_payment(): void
    {
        [$firstHospital] = $this->createPaymentScenario();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
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

        $this->getJson('/api/payments/'.$payment->id.'/status?hospital='.$firstHospital->slug)
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_status_endpoint_returns_the_required_payment_fields(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario();
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);

        $this->getJson('/api/payments/'.$payment->id.'/status?hospital='.$hospital->slug)
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
    private function createPaymentScenario(): array
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
            ]);

        return [$hospital, $appointment, $payment];
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
