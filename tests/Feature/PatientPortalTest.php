<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentLifecycleNotifications;
use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\SlotCapacity;
use App\Models\User;
use App\Services\WhatsAppService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PatientPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00:00'));
    }

    public function test_valid_signed_url_renders_patient_appointment_details(): void
    {
        [$hospital, $appointment] = $this->createAppointment();
        $url = URL::temporarySignedRoute(
            'patient-portal.show',
            now()->addDays(7),
            ['appointment' => $appointment->id],
        );

        $this->get($url)
            ->assertOk()
            ->assertSee($hospital->name)
            ->assertSee($appointment->booking_reference)
            ->assertSee($appointment->name)
            ->assertSee('Add to Calendar')
            ->assertSee('Reschedule')
            ->assertSee('Cancel');
    }

    public function test_invalid_and_expired_signed_urls_are_forbidden(): void
    {
        [, $appointment] = $this->createAppointment();
        $expiredUrl = URL::temporarySignedRoute(
            'patient-portal.show',
            now()->subMinute(),
            ['appointment' => $appointment->id],
        );

        $this->get($expiredUrl)->assertForbidden();
        $this->get('/p/'.$appointment->id)->assertForbidden();
    }

    public function test_rescheduling_releases_the_old_slot_and_reserves_the_new_slot(): void
    {
        [$hospital, $appointment, $doctor] = $this->createAppointment([
            'preferred_date' => '2026-10-10',
            'slot_start_time' => '09:00',
            'slot_end_time' => '09:30',
        ]);
        DoctorAvailability::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
            'is_active' => true,
        ]);
        SlotCapacity::query()->create([
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => '2026-10-10',
            'slot_time' => '09:00',
            'capacity' => 1,
            'booked_count' => 1,
        ]);
        Queue::fake([SendAppointmentLifecycleNotifications::class]);
        $rescheduleUrl = URL::temporarySignedRoute(
            'patient-portal.reschedule',
            now()->addDays(7),
            ['appointment' => $appointment->id],
        );

        $this->get($rescheduleUrl)
            ->assertOk()
            ->assertSee('2026-10-12|09:00');
        $this->post($rescheduleUrl, ['date_time' => '2026-10-12|09:00'])
            ->assertRedirect();

        $this->assertSame('2026-10-12', $appointment->fresh()->preferred_date->format('Y-m-d'));
        $this->assertSame('09:00', substr((string) $appointment->fresh()->slot_start_time, 0, 5));
        $this->assertSame(
            0,
            (int) SlotCapacity::withoutGlobalScope('hospital')
                ->where('hospital_id', $hospital->id)
                ->where('doctor_id', $doctor->id)
                ->whereDate('slot_date', '2026-10-10')
                ->whereTime('slot_time', '09:00:00')
                ->value('booked_count'),
        );
        $this->assertSame(
            1,
            (int) SlotCapacity::withoutGlobalScope('hospital')
                ->where('hospital_id', $hospital->id)
                ->where('doctor_id', $doctor->id)
                ->whereDate('slot_date', '2026-10-12')
                ->whereTime('slot_time', '09:00:00')
                ->value('booked_count'),
        );
    }

    public function test_portal_cancellation_with_less_than_24_hours_notice_is_rejected(): void
    {
        [, $appointment] = $this->createAppointment([
            'preferred_date' => '2026-10-02',
            'slot_start_time' => '07:00',
        ]);
        $cancelUrl = URL::temporarySignedRoute(
            'patient-portal.cancel',
            now()->addDays(7),
            ['appointment' => $appointment->id],
        );

        $this->from(URL::temporarySignedRoute(
            'patient-portal.show',
            now()->addDays(7),
            ['appointment' => $appointment->id],
        ))
            ->post($cancelUrl)
            ->assertRedirect()
            ->assertSessionHasErrors('cancellation');

        $this->assertSame(AppointmentRequest::STATUS_PENDING, $appointment->fresh()->status);
    }

    public function test_portal_cancellation_48_hours_ahead_succeeds_and_releases_capacity(): void
    {
        [$hospital, $appointment, $doctor] = $this->createAppointment([
            'preferred_date' => '2026-10-03',
            'slot_start_time' => '08:00',
        ]);
        SlotCapacity::query()->create([
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => '2026-10-03',
            'slot_time' => '08:00',
            'capacity' => 1,
            'booked_count' => 1,
        ]);
        Queue::fake([SendAppointmentLifecycleNotifications::class]);
        $this->mock(WhatsAppService::class)
            ->shouldReceive('sendMessage')
            ->once()
            ->andReturn(['success' => true, 'message' => 'sent']);
        $cancelUrl = URL::temporarySignedRoute(
            'patient-portal.cancel',
            now()->addDays(7),
            ['appointment' => $appointment->id],
        );

        $this->post($cancelUrl)->assertRedirect();

        $this->assertSame(AppointmentRequest::STATUS_CANCELLED, $appointment->fresh()->status);
        $this->assertSame(
            0,
            (int) SlotCapacity::withoutGlobalScope('hospital')
                ->where('hospital_id', $hospital->id)
                ->where('doctor_id', $doctor->id)
                ->whereDate('slot_date', '2026-10-03')
                ->whereTime('slot_time', '08:00')
                ->value('booked_count'),
        );
    }

    /**
     * @return array{Hospital, AppointmentRequest, User}
     */
    private function createAppointment(array $overrides = []): array
    {
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'professional',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        app()->instance('currentHospital', $hospital);
        $doctor = User::factory()->for($hospital, 'hospital')->create([
            'role' => 'doctor',
            'is_doctor' => true,
            'is_active' => true,
        ]);
        $appointment = AppointmentRequest::query()->create(array_merge([
            'hospital_id' => $hospital->id,
            'session_id' => 'portal-'.str()->uuid(),
            'name' => 'Portal Patient',
            'phone' => '254700000000',
            'preferred_date' => '2026-10-10',
            'slot_start_time' => '09:00',
            'slot_end_time' => '09:30',
            'doctor_id' => $doctor->id,
            'reason' => 'Consultation',
            'raw_message' => 'Book an appointment',
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'unpaid',
        ], $overrides));

        return [$hospital, $appointment, $doctor];
    }
}
