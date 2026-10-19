<?php

namespace Tests\Feature;

use App\Events\AppointmentCancelled;
use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentLifecycleService;
use App\Services\DoctorAvailabilityService;
use App\Services\NoShowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SlotCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_capacity_slot_rejects_a_second_booking(): void
    {
        [$hospital, $doctor, $service] = $this->bookingContext(1);

        $firstAppointment = $this->book($doctor, $service, 'capacity-one-first');
        $secondAppointment = $this->book($doctor, $service, 'capacity-one-second');

        $this->assertNotNull($firstAppointment);
        $this->assertNull($secondAppointment);
        $this->assertDatabaseCount('slot_holds', 1);
        $this->assertDatabaseHas('slot_capacity', [
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => '2026-10-03 00:00:00',
            'slot_time' => '09:00:00',
            'capacity' => 1,
            'booked_count' => 1,
        ]);
    }

    public function test_three_capacity_slot_rejects_a_fourth_booking(): void
    {
        [$hospital, $doctor, $service] = $this->bookingContext(3);

        $appointments = [];
        foreach (range(1, 4) as $bookingNumber) {
            $appointments[] = $this->book(
                $doctor,
                $service,
                'capacity-three-'.$bookingNumber,
            );
        }

        $this->assertCount(3, array_filter($appointments));
        $this->assertNull($appointments[3]);
        $this->assertDatabaseCount('slot_holds', 3);
        $this->assertDatabaseHas('slot_capacity', [
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => '2026-10-03 00:00:00',
            'slot_time' => '09:00:00',
            'capacity' => 3,
            'booked_count' => 3,
        ]);
    }

    public function test_cancelling_a_booking_releases_capacity_for_a_new_booking(): void
    {
        [$hospital, $doctor, $service] = $this->bookingContext(1);
        Queue::fake();
        Event::fake([AppointmentCancelled::class]);
        $appointment = $this->book($doctor, $service, 'capacity-cancelled');
        $staff = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $this->assertNotNull($appointment);

        app(AppointmentLifecycleService::class)->cancel(
            $appointment->id,
            $hospital->id,
            $staff,
            staffOverride: true,
        );

        $this->assertDatabaseHas('slot_capacity', [
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => '2026-10-03 00:00:00',
            'slot_time' => '09:00:00',
            'booked_count' => 0,
        ]);
        $this->assertSame(AppointmentRequest::STATUS_CANCELLED, $appointment->fresh()->status);

        $newAppointment = $this->book($doctor, $service, 'capacity-after-cancel');

        $this->assertNotNull($newAppointment);
        $this->assertDatabaseHas('slot_capacity', [
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => '2026-10-03 00:00:00',
            'slot_time' => '09:00:00',
            'booked_count' => 1,
        ]);
    }

    public function test_rescheduling_a_booking_moves_its_capacity_reservation(): void
    {
        [$hospital, $doctor, $service] = $this->bookingContext(1);
        $staff = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        $appointment = $this->book($doctor, $service, 'capacity-rescheduled');

        $this->assertNotNull($appointment);

        app(AppointmentLifecycleService::class)->reschedule(
            $appointment->id,
            $hospital->id,
            $doctor->id,
            '2026-10-03',
            '09:30',
            $staff,
            staffOverride: true,
        );

        $this->assertDatabaseHas('slot_capacity', [
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => '2026-10-03 00:00:00',
            'slot_time' => '09:00:00',
            'booked_count' => 0,
        ]);
        $this->assertDatabaseHas('slot_capacity', [
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => '2026-10-03 00:00:00',
            'slot_time' => '09:30:00',
            'booked_count' => 1,
        ]);
    }

    public function test_marking_a_booking_as_no_show_releases_its_capacity(): void
    {
        [$hospital, $doctor, $service] = $this->bookingContext(1);
        $appointment = $this->book($doctor, $service, 'capacity-no-show');

        $this->assertNotNull($appointment);
        $appointment->forceFill([
            'status' => AppointmentRequest::STATUS_CONFIRMED,
            'payment_status' => 'paid',
            'phone' => null,
        ])->save();

        app(NoShowService::class)->markAsNoShow($appointment);

        $this->assertSame(AppointmentRequest::STATUS_NO_SHOW, $appointment->fresh()->status);
        $this->assertDatabaseHas('slot_capacity', [
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => '2026-10-03 00:00:00',
            'slot_time' => '09:00:00',
            'booked_count' => 0,
        ]);
    }

    public function test_expiring_a_booking_hold_releases_its_capacity(): void
    {
        [$hospital, $doctor, $service] = $this->bookingContext(1);
        $appointment = $this->book($doctor, $service, 'capacity-expired');

        $this->assertNotNull($appointment);
        $this->travel(6)->minutes();

        $this->artisan('app:cleanup-slot-holds')->assertExitCode(0);

        $this->assertSame(AppointmentRequest::STATUS_EXPIRED, $appointment->fresh()->status);
        $this->assertDatabaseHas('slot_capacity', [
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => '2026-10-03 00:00:00',
            'slot_time' => '09:00:00',
            'booked_count' => 0,
        ]);
    }

    /**
     * @return array{Hospital, User, Service}
     */
    private function bookingContext(int $capacity): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00:00'));
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        app()->instance('currentHospital', $hospital);

        $doctor = User::factory()->for($hospital, 'hospital')->create([
            'is_doctor' => true,
            'is_active' => true,
            'role' => 'doctor',
        ]);
        DoctorAvailability::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => CarbonImmutable::parse('2026-10-03')->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => $capacity,
            'is_active' => true,
        ]);
        $service = Service::query()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Dental Cleaning',
            'price' => 500,
            'duration_minutes' => 30,
            'category' => 'Dental',
            'is_active' => true,
        ]);

        return [$hospital, $doctor, $service];
    }

    private function book(User $doctor, Service $service, string $sessionId): ?AppointmentRequest
    {
        return app(DoctorAvailabilityService::class)->bookAppointment(
            '2026-10-03',
            '09:00',
            [
                'name' => 'Test Patient',
                'phone' => '254748249882',
                'reason' => $service->name,
                'raw_message' => 'Book appointment',
                'session_id' => $sessionId,
            ],
            $doctor->id,
            $service,
        );
    }
}
