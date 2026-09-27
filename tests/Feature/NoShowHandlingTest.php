<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\User;
use App\Notifications\NoShowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NoShowHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_can_mark_owned_paid_appointment_as_no_show(): void
    {
        Notification::fake();
        $doctor = User::factory()->create(['is_doctor' => true]);
        $appointment = $this->appointment($doctor);

        $this->actingAs($doctor)
            ->post(route('doctor.appointments.no-show', $appointment), ['reason' => 'Patient did not arrive'])
            ->assertRedirect(route('doctor.appointments.show', $appointment));

        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_NO_SHOW,
            'no_show_reason' => 'Patient did not arrive',
        ]);
        $this->assertNotNull($appointment->fresh()->marked_no_show_at);
    }

    public function test_admin_can_mark_paid_appointment_as_no_show(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $appointment = $this->appointment();

        $this->actingAs($admin)
            ->post(route('admin.appointments.no-show', $appointment))
            ->assertRedirect(route('admin.appointments.show', $appointment));

        $this->assertSame(AppointmentRequest::STATUS_NO_SHOW, $appointment->fresh()->status);
    }

    public function test_non_owner_cannot_mark_another_doctors_appointment_as_no_show(): void
    {
        $owner = User::factory()->create(['is_doctor' => true]);
        $otherDoctor = User::factory()->create(['is_doctor' => true]);
        $appointment = $this->appointment($owner);

        $this->actingAs($otherDoctor)
            ->post(route('doctor.appointments.no-show', $appointment))
            ->assertNotFound();

        $this->assertSame(AppointmentRequest::STATUS_CONFIRMED, $appointment->fresh()->status);
    }

    public function test_no_show_requires_confirmed_paid_appointment(): void
    {
        $doctor = User::factory()->create(['is_doctor' => true]);
        $appointment = $this->appointment($doctor, [
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($doctor)
            ->post(route('doctor.appointments.no-show', $appointment))
            ->assertStatus(422);

        $this->assertSame(AppointmentRequest::STATUS_CONFIRMED, $appointment->fresh()->status);
    }

    public function test_scheduled_command_marks_overdue_paid_appointments(): void
    {
        Notification::fake();
        $this->travelTo(now()->setTime(11, 0));
        $appointment = $this->appointment(null, [
            'preferred_date' => today()->toDateString(),
            'slot_end_time' => '10:29:00',
        ]);

        $this->artisan('appointments:mark-no-shows')
            ->expectsOutput('Marked 1 overdue appointment(s) as no-shows.')
            ->assertExitCode(0);

        $this->assertSame(AppointmentRequest::STATUS_NO_SHOW, $appointment->fresh()->status);
    }

    public function test_scheduled_command_skips_appointments_inside_the_grace_period(): void
    {
        Notification::fake();
        $this->travelTo(now()->setTime(11, 0));
        $appointment = $this->appointment(null, [
            'preferred_date' => today()->toDateString(),
            'slot_end_time' => '10:45:00',
        ]);

        $this->artisan('appointments:mark-no-shows')
            ->expectsOutput('Marked 0 overdue appointment(s) as no-shows.')
            ->assertExitCode(0);

        $this->assertSame(AppointmentRequest::STATUS_CONFIRMED, $appointment->fresh()->status);
    }

    public function test_no_show_notification_is_sent_to_the_patient_email(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $appointment = $this->appointment(null, ['email' => 'patient@example.com']);

        $this->actingAs($admin)
            ->post(route('admin.appointments.no-show', $appointment))
            ->assertRedirect();

        Notification::assertSentOnDemand(
            NoShowNotification::class,
            fn (NoShowNotification $notification, array $channels, object $notifiable): bool => $channels === ['mail']
                && $notifiable->routes['mail'] === 'patient@example.com',
        );
    }

    public function test_repeat_no_show_count_is_displayed_on_appointment_details(): void
    {
        $doctor = User::factory()->create(['is_doctor' => true]);
        $this->appointment(null, [
            'phone' => '254712345678',
            'status' => AppointmentRequest::STATUS_NO_SHOW,
            'marked_no_show_at' => now()->subWeek(),
        ]);
        $appointment = $this->appointment($doctor, [
            'phone' => '254712345678',
        ]);

        $this->actingAs($doctor)
            ->get(route('doctor.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('This patient has 1 previous no-show.');
    }

    private function appointment(?User $doctor = null, array $attributes = []): AppointmentRequest
    {
        static $session = 0;

        return AppointmentRequest::query()->create(array_merge([
            'session_id' => 'test-session-'.(++$session),
            'name' => 'Jane Patient',
            'phone' => '254712345678',
            'email' => 'patient@example.com',
            'preferred_date' => today()->toDateString(),
            'reason' => 'Consultation',
            'raw_message' => 'Appointment request',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
            'payment_status' => 'paid',
            'payment_amount' => 500,
            'slot_start_time' => '09:00:00',
            'slot_end_time' => '10:00:00',
            'doctor_id' => $doctor?->id,
        ], $attributes));
    }
}
