<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\User;
use App\Services\BookingChatService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingCapacityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_via_chat_increments_slot_capacity(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00:00'));
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'professional',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        app()->instance('currentHospital', $hospital);

        $doctor = User::factory()->for($hospital, 'hospital')->create([
            'is_doctor' => true,
            'is_active' => true,
            'role' => 'doctor',
        ]);
        $service = $hospital->services()->create([
            'name' => 'Capacity Test Service',
            'price' => 1,
            'duration_minutes' => 30,
            'category' => 'General',
            'is_active' => true,
        ]);
        DoctorAvailability::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => CarbonImmutable::parse('2026-10-03')->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
            'is_active' => true,
        ]);
        $chat = app(BookingChatService::class);
        $sessionId = 'booking-capacity-integration';

        $chat->handle($sessionId, 'I want to book an appointment', 'web');
        $chat->handle($sessionId, 'Capacity Test Service', 'web');
        $chat->handle($sessionId, '2026-10-03', 'web');
        $chat->handle($sessionId, 'Capacity Test Patient', 'web');
        $chat->handle($sessionId, '254748249882', 'web');
        $summary = $chat->handle($sessionId, '09:00', 'web');
        $response = $chat->handle($sessionId, 'yes', 'web');
        $appointment = AppointmentRequest::query()
            ->where('session_id', $sessionId)
            ->firstOrFail();

        $this->assertSame('CONFIRMING', $summary->state);
        $this->assertSame('COMPLETED', $response->state);
        $this->assertModelExists($appointment);
        $this->assertDatabaseHas('slot_capacity', [
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => '2026-10-03 00:00:00',
            'slot_time' => '09:00:00',
            'capacity' => 1,
            'booked_count' => 1,
        ]);
    }
}
