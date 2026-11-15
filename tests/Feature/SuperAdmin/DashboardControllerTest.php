<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_dashboard_counts_and_sums_records_across_hospitals(): void
    {
        $this->travelTo('2026-11-15 12:00:00');
        app()->forgetInstance('currentHospital');
        $hospital = Hospital::factory()->create([
            'name' => 'Nairobi Dashboard Hospital',
            'subscription_plan' => 'enterprise',
        ]);
        $platformHospital = Hospital::factory()->create();
        $superAdmin = User::factory()->create([
            'hospital_id' => $platformHospital->id,
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);
        User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'doctor',
            'is_doctor' => true,
        ]);
        AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'preferred_date' => '2026-11-20',
            'payment_status' => 'paid',
            'payment_amount' => 2500,
            'paid_at' => '2026-11-10 10:00:00',
        ]);
        app()->forgetInstance('currentHospital');

        $this->actingAs($superAdmin)
            ->get(route('superadmin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([$hospital->name, 'enterprise', '1', '1', 'KES 2,500.00']);
    }
}
