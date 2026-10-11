<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AdminAppointmentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_confirmation_updates_status_timestamp_and_notifies_patient_once(): void
    {
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        $appointment = AppointmentRequest::query()->create([
            'session_id' => 'manual-confirmation-session',
            'name' => 'Jane Doe',
            'phone' => '254748249882',
            'preferred_date' => '2026-10-10',
            'reason' => 'Consultation',
            'raw_message' => 'Jane booked a consultation.',
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'paid',
            'paid_at' => now()->subMinute(),
        ]);

        $this->freezeTime();
        $confirmedAt = now();
        $this->mock(NotificationService::class)
            ->shouldReceive('sendSms')
            ->once()
            ->with('254748249882', Mockery::on(
                fn (string $message): bool => str_contains($message, 'appointment request (ID '.$appointment->id.') has been confirmed'),
            ))
            ->andReturnTrue();

        $this->actingAs($admin)
            ->post(route('admin.appointments.confirm', $appointment->id))
            ->assertRedirect(route('admin.appointments.show', $appointment->id))
            ->assertSessionHas('status', 'Appointment confirmed and patient notified.');

        $confirmedAppointment = $appointment->fresh();
        $this->assertSame(AppointmentRequest::STATUS_CONFIRMED, $confirmedAppointment->status);
        $this->assertSame(
            $confirmedAt->toDateTimeString(),
            $confirmedAppointment->status_updated_at->toDateTimeString(),
        );

        $this->actingAs($admin)
            ->post(route('admin.appointments.confirm', $appointment->id))
            ->assertSessionHas('status', 'Appointment confirmed and patient notified.');

        $this->assertSame(
            $confirmedAt->toDateTimeString(),
            $appointment->fresh()->status_updated_at->toDateTimeString(),
        );
    }
}
