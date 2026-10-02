<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\User;
use Database\Seeders\DoctorUserSeeder;
use Database\Seeders\HospitalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_replaces_generic_doctors_and_preserves_their_appointments(): void
    {
        config([
            'pearlie.doctors.seed_accounts' => [
                [
                    'name' => 'Dr. John Kamau',
                    'email' => 'doctor1@pearlhospital.co.ke',
                    'specialization' => 'General Medicine',
                    'phone' => '0700000001',
                ],
                [
                    'name' => 'Dr. Mary Wanjiru',
                    'email' => 'doctor2@pearlhospital.co.ke',
                    'specialization' => 'Pediatrics',
                    'phone' => '0700000002',
                ],
            ],
        ]);
        $this->seed(HospitalSeeder::class);
        $hospital = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        app()->instance('currentHospital', $hospital);
        User::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Dr. John Kamau',
            'email' => 'doctor1@pearlhospital.co.ke',
            'specialization' => 'General Medicine',
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $genericDoctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Doctor One',
            'email' => 'legacy-doctor-one@example.test',
            'specialization' => 'General Medicine',
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $appointment = AppointmentRequest::query()->create([
            'session_id' => 'legacy-generic-doctor-session',
            'name' => 'Test Patient',
            'phone' => '0712345678',
            'preferred_date' => today()->addDay(),
            'reason' => 'Consultation',
            'raw_message' => 'Book an appointment',
            'status' => AppointmentRequest::STATUS_PENDING,
            'doctor_id' => $genericDoctor->id,
        ]);

        $this->seed(DoctorUserSeeder::class);

        $namedDoctor = User::query()
            ->where('email', 'doctor1@pearl.test')
            ->firstOrFail();
        $this->assertDatabaseMissing('users', ['id' => $genericDoctor->id]);
        $this->assertSame('Dr. Amina Njeri', $namedDoctor->name);
        $this->assertSame($namedDoctor->id, $appointment->fresh()->doctor_id);
        $this->assertDatabaseHas('users', [
            'email' => 'doctor1@pearlhospital.co.ke',
            'is_active' => false,
        ]);
        $this->assertSame(
            0,
            User::withoutGlobalScopes()
                ->where('hospital_id', $hospital->id)
                ->whereIn('name', ['Doctor One', 'Doctor Two', 'Doctor A', 'Doctor B'])
                ->count(),
        );
    }
}
