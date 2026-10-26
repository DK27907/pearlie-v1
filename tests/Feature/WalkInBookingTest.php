<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\Receipt;
use App\Models\Service;
use App\Models\User;
use App\Services\DoctorAvailabilityService;
use App\Services\MpesaService;
use App\Services\PaymentLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class WalkInBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_walk_in_booking(): void
    {
        [$hospital, $admin, $doctor, $service, $date] = $this->bookingContext();

        $response = $this->actingAs($admin)->postJson(route('admin.walk-in.store'), [
            'service_id' => $service->id,
            'doctor_id' => $doctor->id,
            'date' => $date,
            'time' => '09:00',
            'patient_name' => 'Walk-In Patient',
            'phone' => '0748249882',
            'payment_override' => 'none',
        ]);

        $response->assertRedirect();
        $appointment = AppointmentRequest::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^BK-[A-F0-9]{32}$/', $appointment->booking_reference);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'booking_source' => 'walk-in',
            'payment_status' => 'not_required',
            'payment_method' => null,
            'payment_due_at' => null,
        ]);
        $this->assertSame(AppointmentRequest::STATUS_CONFIRMED, $appointment->status);
        $response->assertRedirect(route('admin.walk-in.confirmation', $appointment));
    }

    public function test_walk_in_respects_free_slots_already_occupied_by_chat(): void
    {
        [, $admin, $doctor, $service, $date] = $this->bookingContext();
        $chatAppointment = app(DoctorAvailabilityService::class)->bookAppointment(
            $date,
            '09:00',
            [
                'name' => 'Chat Patient',
                'phone' => '254712345678',
                'reason' => $service->name,
                'raw_message' => 'Chat booking.',
                'session_id' => 'chat-occupies-last-slot',
            ],
            (int) $doctor->id,
            $service,
        );
        $this->assertNotNull($chatAppointment);

        $this->actingAs($admin)
            ->postJson(route('admin.walk-in.store'), [
                'service_id' => $service->id,
                'doctor_id' => $doctor->id,
                'date' => $date,
                'time' => '09:00',
                'patient_name' => 'Second Patient',
                'phone' => '0748249882',
                'payment_override' => 'none',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('time');

        $this->assertDatabaseCount('appointment_requests', 1);
        $this->assertSame('chat-occupies-last-slot', AppointmentRequest::query()->value('session_id'));
    }

    public function test_walk_in_doctors_endpoint_excludes_doctors_without_the_service(): void
    {
        [$hospital, $admin, $matchingDoctor, $service] = $this->bookingContext(specialty: 'Cardiology');
        $unmatchedDoctor = $this->createDoctor($hospital, 'Dermatology');
        $this->createAvailability($hospital, $unmatchedDoctor, '2026-10-27');
        $doctorWithoutAvailability = $this->createDoctor($hospital, 'Cardiology');
        $otherHospital = Hospital::factory()->create();
        app()->instance('currentHospital', $otherHospital);
        $foreignDoctor = $this->createDoctor($otherHospital, 'Cardiology');
        $this->createAvailability($otherHospital, $foreignDoctor, '2026-10-27');
        app()->instance('currentHospital', $hospital);

        $this->actingAs($admin)
            ->getJson(route('admin.walk-in.doctors', ['service_id' => $service->id]))
            ->assertOk()
            ->assertJsonFragment(['id' => $matchingDoctor->id])
            ->assertJsonMissing(['id' => $unmatchedDoctor->id])
            ->assertJsonMissing(['id' => $doctorWithoutAvailability->id])
            ->assertJsonMissing(['id' => $foreignDoctor->id]);
    }

    public function test_walk_in_appointment_appears_on_doctor_dashboard(): void
    {
        [$hospital, $admin, $doctor, $service, $date] = $this->bookingContext(
            paymentMode: 'cash',
            date: '2026-10-26',
        );
        Storage::fake();

        $this->actingAs($admin)
            ->postJson(route('admin.walk-in.store'), [
                'service_id' => $service->id,
                'doctor_id' => $doctor->id,
                'date' => $date,
                'time' => '09:00',
                'patient_name' => 'Dashboard Walk-In',
                'phone' => '0748249882',
            ])
            ->assertRedirect();
        $appointment = AppointmentRequest::query()->firstOrFail();
        app(PaymentLifecycleService::class)->markPaidAtHospital(
            $appointment,
            (float) $appointment->payment_amount,
            'cash',
            $admin,
        );

        $this->actingAs($doctor)
            ->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee('Dashboard Walk-In')
            ->assertSee($appointment->booking_reference)
            ->assertSee($service->name)
            ->assertSee('09:00')
            ->assertSee('Complete')
            ->assertSee('No-show');
    }

    public function test_walk_in_payment_override_none_on_mpesa_hospital_creates_no_mpesa_intent(): void
    {
        [$hospital, $admin, $doctor, $service, $date] = $this->bookingContext(paymentMode: 'mpesa');
        $this->mock(MpesaService::class)->shouldNotReceive('initiateStkPush');

        $this->actingAs($admin)
            ->postJson(route('admin.walk-in.store'), [
                'service_id' => $service->id,
                'doctor_id' => $doctor->id,
                'date' => $date,
                'time' => '09:00',
                'patient_name' => 'No Payment Walk-In',
                'phone' => '0748249882',
                'payment_override' => 'none',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('appointment_requests', [
            'hospital_id' => $hospital->id,
            'booking_source' => 'walk-in',
            'payment_status' => 'not_required',
            'payment_method' => null,
            'payment_due_at' => null,
        ]);
        $this->assertDatabaseCount('mpesa_payments', 0);
    }

    public function test_walk_in_payment_override_cash_is_due_at_the_hospital(): void
    {
        [$hospital, $admin, $doctor, $service, $date] = $this->bookingContext(paymentMode: 'mpesa');
        $this->mock(MpesaService::class)->shouldNotReceive('initiateStkPush');

        $this->actingAs($admin)
            ->postJson(route('admin.walk-in.store'), [
                'service_id' => $service->id,
                'doctor_id' => $doctor->id,
                'date' => $date,
                'time' => '09:00',
                'patient_name' => 'Cash Walk-In',
                'phone' => '0748249882',
                'payment_override' => 'cash',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('appointment_requests', [
            'hospital_id' => $hospital->id,
            'booking_source' => 'walk-in',
            'payment_status' => 'pending',
            'payment_method' => 'cash',
            'payment_due_at' => 'at_hospital',
        ]);
        $this->assertDatabaseCount('mpesa_payments', 0);
    }

    public function test_walk_in_requires_slot_capacity_lock_for_two_stores_on_the_last_slot(): void
    {
        [$hospital, $admin, $doctor, $service, $date] = $this->bookingContext();
        $payload = [
            'service_id' => $service->id,
            'doctor_id' => $doctor->id,
            'date' => $date,
            'time' => '09:00',
            'patient_name' => 'Capacity Patient',
            'phone' => '0748249882',
            'payment_override' => 'none',
        ];

        $firstResponse = $this->actingAs($admin)
            ->postJson(route('admin.walk-in.store'), $payload);
        $firstResponse->assertRedirect();

        $this->actingAs($admin)
            ->postJson(route('admin.walk-in.store'), array_merge($payload, [
                'patient_name' => 'Concurrent Patient',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('time');

        $this->assertDatabaseCount('appointment_requests', 1);
        $this->assertDatabaseHas('slot_capacity', [
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => $date,
            'slot_time' => '09:00:00',
            'capacity' => 1,
            'booked_count' => 1,
        ]);
    }

    public function test_mark_paid_on_arrival_prints_receipt_with_booking_reference(): void
    {
        [$hospital, $admin, $doctor, $service, $date] = $this->bookingContext(paymentMode: 'cash');
        Storage::fake();
        $this->actingAs($admin)
            ->postJson(route('admin.walk-in.store'), [
                'service_id' => $service->id,
                'doctor_id' => $doctor->id,
                'date' => $date,
                'time' => '09:00',
                'patient_name' => 'Receipt Walk-In',
                'phone' => '0748249882',
            ])
            ->assertRedirect();
        $appointment = AppointmentRequest::query()->firstOrFail();

        $markPaidResponse = $this->actingAs($admin)
            ->post(route('admin.walk-in.mark-paid', $appointment));

        $markPaidResponse->assertRedirect();
        $printResponse = $this->get((string) $markPaidResponse->headers->get('Location'));
        $printResponse->assertOk();
        $receipt = Receipt::query()->where('appointment_request_id', $appointment->id)->firstOrFail();
        $this->assertSame('paid', $appointment->fresh()->payment_status);
        $this->assertSame($appointment->booking_reference, $receipt->appointment->booking_reference);
        $this->assertStringContainsString(
            $appointment->booking_reference,
            view('receipts.appointment', [
                'receipt' => $receipt->load('issuedBy'),
                'hospital' => $hospital,
                'appointment' => $appointment->fresh(['doctor', 'service']),
                'mpesa' => null,
                'letterheadDataUri' => null,
                'footerDataUri' => null,
                'signatureDataUri' => null,
            ])->render(),
        );
        Storage::assertExists($receipt->pdf_path);
    }

    public function test_hospital_mpesa_default_is_used_unless_walk_in_is_overridden(): void
    {
        [$hospital, $admin, $doctor, $service, $date] = $this->bookingContext(paymentMode: 'mpesa');
        $mpesa = Mockery::mock(MpesaService::class);
        $mpesa->shouldReceive('initiateStkPush')
            ->once()
            ->with(Mockery::on(fn (AppointmentRequest $appointment): bool => $appointment->booking_source === 'walk-in'
                && $appointment->payment_method === 'mpesa'
                && $appointment->payment_due_at === 'at_hospital'))
            ->andReturn([
                'success' => true,
                'payment_id' => 1,
                'checkout_request_id' => 'walk-in-checkout',
                'message' => 'Prompt sent.',
            ]);
        $this->app->instance(MpesaService::class, $mpesa);

        $this->actingAs($admin)
            ->postJson(route('admin.walk-in.store'), [
                'service_id' => $service->id,
                'doctor_id' => $doctor->id,
                'date' => $date,
                'time' => '09:00',
                'patient_name' => 'M-Pesa Walk-In',
                'phone' => '0748249882',
            ])
            ->assertRedirect();

        $this->assertSame('mpesa', $hospital->bookingPaymentMode());
        $this->assertDatabaseHas('appointment_requests', [
            'hospital_id' => $hospital->id,
            'booking_source' => 'walk-in',
            'payment_status' => 'pending',
            'payment_method' => 'mpesa',
            'payment_due_at' => 'at_hospital',
        ]);
    }

    private function bookingContext(
        string $paymentMode = 'none',
        string $specialty = '',
        string $date = '2026-10-27',
    ): array {
        $this->travelTo(CarbonImmutable::parse($date.' 08:00:00'));
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
            'booking_payment_mode' => $paymentMode,
        ]);
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'is_active' => true,
            'must_change_password' => false,
            'role' => 'hospital_admin',
        ]);
        $doctor = $this->createDoctor($hospital, $specialty);
        $this->createAvailability($hospital, $doctor, $date);
        $service = Service::query()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Walk-In Consultation',
            'price' => 500,
            'duration_minutes' => 30,
            'requires_specialty' => $specialty ?: null,
            'is_active' => true,
        ]);

        return [$hospital, $admin, $doctor, $service, $date];
    }

    private function createDoctor(Hospital $hospital, string $specialty): User
    {
        return User::factory()->for($hospital, 'hospital')->create([
            'is_doctor' => true,
            'is_active' => true,
            'must_change_password' => false,
            'role' => 'doctor',
            'specialization' => $specialty,
        ]);
    }

    private function createAvailability(Hospital $hospital, User $doctor, string $date): DoctorAvailability
    {
        return DoctorAvailability::query()->create([
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'day_of_week' => CarbonImmutable::parse($date)->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
            'is_active' => true,
        ]);
    }
}
