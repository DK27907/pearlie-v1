<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\User;
use App\Services\PearlieService;
use App\Services\PearlieServiceV2;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PearlieServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_request_is_recorded_with_a_clear_confirmation(): void
    {
        $result = $this->app->make(PearlieServiceV2::class)->processMessage(
            'I want to book an appointment. My name is Jane Doe, my phone is 0712345678, tomorrow, for a consultation.',
            'test-session',
        );

        $this->assertSame('appointment', $result['source']);
        $this->assertNotNull($result['appointment_id']);
        $this->assertStringContainsString('recorded as pending', $result['response']);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $result['appointment_id'],
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'doctor_id' => null,
            'phone' => '0712345678',
        ]);
    }

    public function test_named_doctor_selection_is_kept_when_the_patient_replies_with_a_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28 08:00'));
        $hospital = Hospital::query()->create([
            'name' => 'Test Hospital',
            'slug' => 'doctor-selection',
            'subscription_plan' => 'professional',
            'subscription_status' => 'active',
            'is_active' => true,
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'no_show_grace_minutes' => 30,
            'default_language' => 'en',
            'supported_languages' => ['en', 'sw'],
        ]);
        app()->instance('currentHospital', $hospital);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Dr. John Kamau',
            'is_doctor' => true,
            'role' => 'doctor',
            'specialization' => 'General Medicine',
        ]);
        DoctorAvailability::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
            'is_active' => true,
        ]);
        $assistant = app(PearlieService::class);
        $sessionId = 'named-doctor-booking-session';

        $assistant->processMessage('I want to book with Dr. Kamau', $sessionId);
        $assistant->processMessage('Jane Doe', $sessionId);
        $assistant->processMessage('0712345678', $sessionId);
        $assistant->processMessage('General consultation', $sessionId);
        $availabilityReply = $assistant->processMessage('2026-09-28', $sessionId);
        $confirmation = $assistant->processMessage('10:00', $sessionId);
        $booking = $assistant->processMessage('YES', $sessionId);

        $this->assertStringContainsString('Dr. John Kamau', $availabilityReply['response']);
        $this->assertStringNotContainsString('Doctor One', $availabilityReply['response']);
        $this->assertStringContainsString('with Dr. John Kamau', $confirmation['response']);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $booking['appointment_id'],
            'doctor_id' => $doctor->id,
            'slot_start_time' => '10:00',
        ]);
    }

    public function test_ambiguous_doctor_matches_prompt_for_a_specific_doctor(): void
    {
        $hospital = Hospital::query()->create([
            'name' => 'Test Hospital',
            'slug' => 'ambiguous-doctor-selection',
            'subscription_plan' => 'professional',
            'subscription_status' => 'active',
            'is_active' => true,
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'no_show_grace_minutes' => 30,
            'default_language' => 'en',
            'supported_languages' => ['en', 'sw'],
        ]);
        app()->instance('currentHospital', $hospital);
        User::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Dr. Aaron Lee',
            'email' => 'aaron.lee@example.test',
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        User::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Dr. Brian Lee',
            'email' => 'brian.lee@example.test',
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $assistant = app(PearlieService::class);
        $sessionId = 'ambiguous-doctor-booking-session';

        $clarification = $assistant->processMessage('I want to book with Dr. Lee', $sessionId);
        $nextPrompt = $assistant->processMessage('Dr. Aaron Lee', $sessionId);

        $this->assertStringContainsString('More than one doctor matches', $clarification['response']);
        $this->assertStringContainsString('Dr. Aaron Lee', $clarification['response']);
        $this->assertStringContainsString('Dr. Brian Lee', $clarification['response']);
        $this->assertStringNotContainsString('What is your full name?', $clarification['response']);
        $this->assertStringContainsString('What is your full name?', $nextPrompt['response']);
    }

    public function test_full_service_name_and_doctor_name_are_kept_in_the_booking(): void
    {
        $hospital = $this->createBookingHospital('service-doctor-match');
        $service = $hospital->services()->create([
            'name' => 'Dental Cleaning',
            'price' => 2500,
            'duration_minutes' => 30,
            'category' => 'Dental',
            'is_active' => true,
        ]);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Dr. Amina Njeri',
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $sessionId = 'full-service-doctor-match';

        $result = app(PearlieService::class)->processMessage(
            'Please book Dental Cleaning with Dr. Amina Njeri. My name is Jane Doe and my phone is 0712345678.',
            $sessionId,
        );
        $pendingBooking = $this->pendingBooking($sessionId);

        $this->assertSame('booking_details', $result['source']);
        $this->assertStringContainsString('Dental Cleaning', $result['response']);
        $this->assertStringNotContainsString('KSh', $result['response']);
        $this->assertStringNotContainsString('Which service do you need?', $result['response']);
        $this->assertSame($service->id, $pendingBooking['service_id']);
        $this->assertSame($doctor->id, $pendingBooking['preferred_doctor_id']);
        $this->assertSame('Dental Cleaning', $pendingBooking['reason']);
    }

    public function test_unique_partial_service_name_selects_its_service(): void
    {
        $hospital = $this->createBookingHospital('partial-service-match');
        $service = $hospital->services()->create([
            'name' => 'Dental Cleaning',
            'price' => 2500,
            'duration_minutes' => 30,
            'category' => 'Dental',
            'is_active' => true,
        ]);
        $sessionId = 'unique-partial-service-match';

        $result = app(PearlieService::class)->processMessage(
            'I need a dental appointment. My name is Jane Doe and my phone is 0712345678.',
            $sessionId,
        );
        $pendingBooking = $this->pendingBooking($sessionId);

        $this->assertSame('booking_details', $result['source']);
        $this->assertSame($service->id, $pendingBooking['service_id']);
        $this->assertSame('Dental Cleaning', $pendingBooking['reason']);
    }

    public function test_ambiguous_partial_service_name_prompts_for_a_service(): void
    {
        $hospital = $this->createBookingHospital('ambiguous-partial-service-match');
        $hospital->services()->createMany([
            [
                'name' => 'Dental Cleaning',
                'price' => 2500,
                'duration_minutes' => 30,
                'category' => 'Dental',
                'is_active' => true,
            ],
            [
                'name' => 'Dental Examination',
                'price' => 3000,
                'duration_minutes' => 30,
                'category' => 'Dental',
                'is_active' => true,
            ],
        ]);
        $sessionId = 'ambiguous-partial-service-match';

        $result = app(PearlieService::class)->processMessage(
            'I need a dental appointment. My name is Jane Doe and my phone is 0712345678.',
            $sessionId,
        );
        $pendingBooking = $this->pendingBooking($sessionId);

        $this->assertSame('booking_details', $result['source']);
        $this->assertStringContainsString('Which service do you need?', $result['response']);
        $this->assertArrayNotHasKey('service_id', $pendingBooking);
    }

    public function test_unmatched_service_request_prompts_for_a_service(): void
    {
        $hospital = $this->createBookingHospital('unmatched-service-request');
        $hospital->services()->create([
            'name' => 'Dental Cleaning',
            'price' => 2500,
            'duration_minutes' => 30,
            'category' => 'Dental',
            'is_active' => true,
        ]);
        $sessionId = 'unmatched-service-request';

        $result = app(PearlieService::class)->processMessage(
            'I want to book an appointment. My name is Jane Doe and my phone is 0712345678.',
            $sessionId,
        );
        $pendingBooking = $this->pendingBooking($sessionId);

        $this->assertSame('booking_details', $result['source']);
        $this->assertStringContainsString('Which service do you need?', $result['response']);
        $this->assertArrayNotHasKey('service_id', $pendingBooking);
    }

    public function test_service_reply_is_matched_after_the_booking_asks_which_service(): void
    {
        $hospital = $this->createBookingHospital('service-reply-match');
        $service = $hospital->services()->create([
            'name' => 'Dental Cleaning',
            'price' => 2500,
            'duration_minutes' => 30,
            'category' => 'Dental',
            'is_active' => true,
        ]);
        $assistant = app(PearlieService::class);
        $sessionId = 'service-reply-match';

        $assistant->processMessage(
            'I want to book an appointment. My name is Jane Doe and my phone is 0712345678.',
            $sessionId,
        );
        $result = $assistant->processMessage('Dental Cleaning', $sessionId);
        $pendingBooking = $this->pendingBooking($sessionId);

        $this->assertSame('booking_details', $result['source']);
        $this->assertSame($service->id, $pendingBooking['service_id']);
        $this->assertSame('Dental Cleaning', $pendingBooking['reason']);
    }

    private function createBookingHospital(string $slug): Hospital
    {
        $hospital = Hospital::query()->create([
            'name' => 'Test Hospital',
            'slug' => $slug,
            'subscription_plan' => 'professional',
            'subscription_status' => 'active',
            'is_active' => true,
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'no_show_grace_minutes' => 30,
            'default_language' => 'en',
            'supported_languages' => ['en', 'sw'],
        ]);
        app()->instance('currentHospital', $hospital);

        return $hospital;
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingBooking(string $sessionId): array
    {
        $pendingBooking = Cache::get('pending_appointment_booking_'.hash('sha256', $sessionId));
        $this->assertIsArray($pendingBooking);

        return $pendingBooking;
    }
}
