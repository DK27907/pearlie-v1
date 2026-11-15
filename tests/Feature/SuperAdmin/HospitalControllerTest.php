<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HospitalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_tenant_record_counts_in_the_hospital_list(): void
    {
        app()->forgetInstance('currentHospital');
        $hospital = Hospital::factory()->create([
            'name' => 'Nairobi Oversight Hospital',
            'subscription_plan' => 'professional',
        ]);
        $platformHospital = Hospital::factory()->create();
        $superAdmin = User::factory()->create([
            'hospital_id' => $platformHospital->id,
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);
        User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'hospital_admin',
            'is_admin' => true,
        ]);
        AppointmentRequest::factory()->create(['hospital_id' => $hospital->id]);
        app()->forgetInstance('currentHospital');

        $this->actingAs($superAdmin)
            ->get(route('superadmin.hospitals.index'))
            ->assertOk()
            ->assertSeeInOrder([$hospital->name, 'professional', '1', '1']);
    }

    public function test_super_admin_sees_tenant_record_counts_in_hospital_details(): void
    {
        app()->forgetInstance('currentHospital');
        $hospital = Hospital::factory()->create([
            'name' => 'Nairobi Oversight Hospital',
            'subscription_plan' => 'professional',
        ]);
        $platformHospital = Hospital::factory()->create();
        $superAdmin = User::factory()->create([
            'hospital_id' => $platformHospital->id,
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);
        User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'hospital_admin',
            'is_admin' => true,
        ]);
        User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'doctor',
            'is_doctor' => true,
        ]);
        AppointmentRequest::factory()->create(['hospital_id' => $hospital->id]);
        $hospital->escalations()->create([
            'session_id' => 'oversight-session',
            'user_message' => 'Please contact me.',
        ]);
        app()->forgetInstance('currentHospital');

        $this->actingAs($superAdmin)
            ->get(route('superadmin.hospitals.show', $hospital))
            ->assertOk()
            ->assertSeeInOrder(['Users', '2', 'Doctors', '1', 'Appointments', '1', 'Escalations', '1']);
    }
}
