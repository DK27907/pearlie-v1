<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_shows_operational_statistics(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        AppointmentRequest::query()->create([
            'session_id' => 'dashboard-appointment',
            'name' => 'Recent Patient',
            'phone' => '254712345678',
            'preferred_date' => today(),
            'reason' => 'Consultation',
            'raw_message' => 'Appointment request',
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Admin dashboard')
            ->assertSee('Total conversations')
            ->assertSee('Pending appointments')
            ->assertSee('Recent Patient');
    }

    public function test_non_admin_cannot_access_admin_dashboard(): void
    {
        $doctor = User::factory()->create(['is_doctor' => true]);

        $this->actingAs($doctor)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }
}
