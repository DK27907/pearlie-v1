<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\DoctorUnavailableDate;
use App\Models\Hospital;
use App\Models\User;
use App\Notifications\DailyDoctorSummary;
use App\Services\DoctorAvailabilityService;
use App\Services\DoctorNotificationService;
use App\Services\PearlieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DoctorDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        app()->instance(
            'currentHospital',
            Hospital::query()->where('slug', 'pearl')->firstOrFail(),
        );
    }

    public function test_doctor_can_access_dashboard(): void
    {
        $doctor = User::factory()->create(['is_doctor' => true]);

        $this->actingAs($doctor)
            ->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee('Doctor workspace')
            ->assertSee('Patients today');
    }

    public function test_non_doctor_cannot_access_doctor_dashboard(): void
    {
        $patient = User::factory()->create(['is_doctor' => false]);

        $this->actingAs($patient)
            ->get(route('doctor.dashboard'))
            ->assertForbidden();
    }

    public function test_doctor_can_update_availability(): void
    {
        $doctor = User::factory()->create(['is_doctor' => true]);
        $availability = [];

        foreach (range(0, 6) as $dayOfWeek) {
            $availability[] = [
                'day_of_week' => $dayOfWeek,
                'start_time' => '09:00',
                'end_time' => '17:00',
                'slot_duration_minutes' => 30,
                'max_patients_per_slot' => 1,
                'is_active' => $dayOfWeek >= 1 && $dayOfWeek <= 5,
            ];
        }

        $this->actingAs($doctor)
            ->post(route('doctor.availability.update'), ['availabilities' => $availability])
            ->assertRedirect()
            ->assertSessionHas('status', 'Availability updated.');

        $this->assertDatabaseHas('doctor_availabilities', [
            'doctor_id' => $doctor->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'is_active' => 1,
        ]);
        $this->assertDatabaseCount('doctor_availabilities', 7);
    }

    public function test_doctor_sees_only_own_appointments(): void
    {
        $doctor = User::factory()->create(['is_doctor' => true]);
        $otherDoctor = User::factory()->create(['is_doctor' => true]);
        $ownAppointment = $this->createAppointment($doctor, 'Patient Alpha');
        $otherAppointment = $this->createAppointment($otherDoctor, 'Patient Bravo');

        $this->actingAs($doctor)
            ->get(route('doctor.appointments'))
            ->assertOk()
            ->assertSee('Patient Alpha')
            ->assertDontSee('Patient Bravo');

        $this->actingAs($doctor)
            ->get(route('doctor.appointments.show', $otherAppointment->id))
            ->assertNotFound();

        $this->assertModelExists($ownAppointment);
    }

    public function test_ai_returns_available_slots(): void
    {
        $this->travelTo('2026-09-26 08:00:00');
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response(['choices' => []]),
        ]);
        Http::preventStrayRequests();

        $doctor = User::factory()->create([
            'is_doctor' => true,
            'name' => 'Dr. Ada Kamau',
            'specialization' => 'General Medicine',
        ]);
        $this->createAvailability($doctor, 1);

        $result = app(PearlieService::class)->processMessage(
            'Please show me doctor availability on 2026-09-28.',
            'availability-session',
        );

        $this->assertSame('availability', $result['source']);
        $this->assertSame('2026-09-28', $result['date']);
        $this->assertSame(true, $result['doctors'][0]['slots']['09:00']);
        $this->assertStringContainsString('09:00', $result['response']);
    }

    public function test_swahili_booking_collects_details_shows_slots_and_starts_payment(): void
    {
        $this->travelTo('2026-09-26 08:00:00');
        config([
            'mpesa.environment' => 'sandbox',
            'mpesa.consumer_key' => 'test-consumer',
            'mpesa.consumer_secret' => 'test-secret',
            'mpesa.passkey' => 'test-passkey',
            'mpesa.shortcode' => '174379',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
        ]);
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response(['choices' => []]),
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'test-token']),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success',
                'MerchantRequestID' => 'merchant-swahili',
                'CheckoutRequestID' => 'checkout-swahili',
            ]),
        ]);
        Http::preventStrayRequests();

        $doctor = User::factory()->create(['is_doctor' => true]);
        $this->createAvailability($doctor, 1);
        $service = app(PearlieService::class);

        $firstReply = $service->processMessage('Ninataka kuweka miadi', 'swahili-booking');
        $this->assertSame('booking_details', $firstReply['source']);
        $this->assertStringContainsString('tarehe unayopendelea', $firstReply['response']);
        $this->assertStringContainsString('huduma unayohitaji', $firstReply['response']);

        $slotReply = $service->processMessage(
            'Jina langu ni Amina, namba yangu ni 0700000004, 2026-09-28, ninahitaji dental, amina@example.com',
            'swahili-booking',
        );
        $this->assertSame('availability', $slotReply['source']);
        $this->assertStringContainsString('nafasi za miadi', mb_strtolower($slotReply['response']));
        $this->assertStringContainsString('09:00', $slotReply['response']);

        $paymentReply = $service->processMessage('09:00', 'swahili-booking');

        $this->assertSame('appointment_payment', $paymentReply['source']);
        $this->assertStringContainsString('Ombi lako la miadi', $paymentReply['response']);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $paymentReply['appointment_id'],
            'doctor_id' => $doctor->id,
            'name' => 'Amina',
            'phone' => '0700000004',
            'email' => 'amina@example.com',
            'preferred_date' => '2026-09-28 00:00:00',
            'slot_start_time' => '09:00',
            'reason' => 'dental',
            'mpesa_checkout_request_id' => 'checkout-swahili',
        ]);
    }

    public function test_patient_can_book_slot_from_ai(): void
    {
        $this->travelTo('2026-09-26 08:00:00');
        config([
            'mpesa.environment' => 'sandbox',
            'mpesa.consumer_key' => 'test-consumer',
            'mpesa.consumer_secret' => 'test-secret',
            'mpesa.passkey' => 'test-passkey',
            'mpesa.shortcode' => '174379',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
        ]);
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response(['choices' => []]),
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'test-token']),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success',
                'MerchantRequestID' => 'merchant-ai-1',
                'CheckoutRequestID' => 'checkout-ai-1',
            ]),
        ]);
        Http::preventStrayRequests();

        $doctor = User::factory()->create(['is_doctor' => true]);
        $this->createAvailability($doctor, 1);

        $result = app(PearlieService::class)->processMessage(
            'I would like to book an appointment on 2026-09-28 at 9:00 AM. My name is Jane Doe and my phone is 0700000003 for a consultation. jane@example.com',
            'booking-session',
        );

        $this->assertSame('appointment_payment', $result['source']);
        $this->assertNotNull($result['appointment_id']);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $result['appointment_id'],
            'doctor_id' => $doctor->id,
            'preferred_date' => '2026-09-28 00:00:00',
            'slot_start_time' => '09:00',
            'name' => 'Jane Doe',
            'phone' => '0700000003',
            'email' => 'jane@example.com',
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'pending',
            'mpesa_checkout_request_id' => 'checkout-ai-1',
        ]);
    }

    public function test_daily_summary_is_sent(): void
    {
        $this->travelTo('2026-09-28 08:00:00');
        Notification::fake();

        $doctor = User::factory()->create(['is_doctor' => true]);
        $this->createAppointment($doctor, 'Patient Summary', now()->toDateString());

        app(DoctorNotificationService::class)->sendDailySummary($doctor);

        Notification::assertSentTo(
            $doctor,
            DailyDoctorSummary::class,
            fn (DailyDoctorSummary $notification): bool => $notification->toArray($doctor) === [
                'doctor_id' => $doctor->id,
                'date' => '2026-09-28',
                'count' => 1,
            ],
        );
    }

    public function test_unavailable_date_blocks_slots(): void
    {
        $this->travelTo('2026-09-26 08:00:00');

        $doctor = User::factory()->create(['is_doctor' => true]);
        $this->createAvailability($doctor, 1);
        DoctorUnavailableDate::factory()->for($doctor, 'doctor')->create([
            'date' => '2026-09-28',
            'reason' => 'Annual leave',
        ]);

        $service = app(DoctorAvailabilityService::class);

        $this->assertSame(false, $service->getAvailableSlots($doctor->id, '2026-09-28')['09:00']);
        $this->assertFalse($service->isDoctorAvailable($doctor->id, '2026-09-28'));
    }

    private function createAvailability(User $doctor, int $dayOfWeek): DoctorAvailability
    {
        return DoctorAvailability::factory()->for($doctor, 'doctor')->create([
            'day_of_week' => $dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
            'is_active' => true,
        ]);
    }

    private function createAppointment(
        User $doctor,
        string $patientName,
        ?string $date = '2026-09-28',
    ): AppointmentRequest {
        return $doctor->doctorAppointments()->create([
            'session_id' => 'session-'.$patientName,
            'name' => $patientName,
            'phone' => '0700000000',
            'preferred_date' => $date,
            'reason' => 'Consultation',
            'raw_message' => 'Appointment request',
            'status' => AppointmentRequest::STATUS_PENDING,
        ]);
    }
}
