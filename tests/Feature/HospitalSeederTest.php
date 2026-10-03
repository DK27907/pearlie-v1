<?php

namespace Tests\Feature;

use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\DoctorUserSeeder;
use Database\Seeders\HospitalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HospitalSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_seeders_create_the_requested_pearl_and_demo_hospital_catalogs(): void
    {
        $this->seed([HospitalSeeder::class, DoctorUserSeeder::class]);

        $pearl = Hospital::withoutGlobalScopes()->where('slug', 'pearl')->firstOrFail();
        $demo = Hospital::withoutGlobalScopes()->where('slug', 'demo')->firstOrFail();

        $this->assertSame('Pearl Hospital', $pearl->name);
        $this->assertSame('info@pearlhospital.co.ke', $pearl->email);
        $this->assertSame('0707799114', $pearl->phone);
        $this->assertSame('Vin Plaza, Nyeri - Nyahururu Rd, Nyahururu, Kenya', $pearl->address);
        $this->assertSame('https://www.pearlhospital.co.ke', $pearl->website);
        $this->assertSame('Pearlie', $pearl->chatbot_name);
        $this->assertSame('Pearl Hospital', $pearl->site_header_text);
        $this->assertSame('© 2026 Pearl Hospital. All rights reserved.', $pearl->site_footer_text);

        $pearlServices = $pearl->services()
            ->active()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Service $service): array => [
                $service->name => [(int) $service->price, $service->duration_minutes],
            ])
            ->all();

        $this->assertSame([
            'Antenatal Clinic Visit' => [1000, 30],
            'CT Scan (Head/Chest/Abdomen)' => [12000, 45],
            'Dental Checkup & Cleaning' => [2500, 30],
            'Digital X-Ray' => [3000, 20],
            'General Consultation' => [1500, 30],
            'Oncology Consultation' => [5000, 45],
            'Optical Eye Checkup' => [1500, 30],
            'Physiotherapy Session' => [2000, 45],
            'Renal Dialysis Session' => [15000, 240],
            'Ultrasound Scan (Obstetric)' => [1000, 30],
        ], $pearlServices);

        $this->assertSame('Nairobi Medical Centre', $demo->name);
        $this->assertSame('Nairobie', $demo->chatbot_name);
        $this->assertSame(
            [
                'Dental Checkup',
                'General Consultation',
                'Paediatric Consultation',
                'Ultrasound Scan',
            ],
            $demo->services()->active()->orderBy('name')->pluck('name')->all(),
        );

        $pearlDoctors = User::withoutGlobalScopes()
            ->where('hospital_id', $pearl->id)
            ->where('is_doctor', true)
            ->where('is_active', true)
            ->orderBy('email')
            ->get();
        $this->assertCount(5, $pearlDoctors);
        $this->assertDatabaseHas('users', [
            'hospital_id' => $pearl->id,
            'email' => 'doctor1@pearl.test',
            'name' => 'Dr. Amina Njeri',
            'specialization' => 'Dentist',
            'consultation_fee' => 2500,
            'licence_number' => 'KMPDC-12345',
        ]);

        foreach ($pearlDoctors as $doctor) {
            $availability = DoctorAvailability::withoutGlobalScopes()
                ->where('doctor_id', $doctor->id)
                ->orderBy('day_of_week')
                ->get()
                ->keyBy('day_of_week');

            $this->assertCount(7, $availability);
            $this->assertFalse($availability[0]->is_active);
            foreach (range(1, 6) as $dayOfWeek) {
                $this->assertTrue($availability[$dayOfWeek]->is_active);
                $this->assertSame('08:00', $availability[$dayOfWeek]->start_time);
                $this->assertSame('17:00', $availability[$dayOfWeek]->end_time);
            }
        }

        $demoDoctors = User::withoutGlobalScopes()
            ->where('hospital_id', $demo->id)
            ->where('is_doctor', true)
            ->where('is_active', true)
            ->get();
        $this->assertCount(2, $demoDoctors);

        foreach ($demoDoctors as $doctor) {
            $availability = DoctorAvailability::withoutGlobalScopes()
                ->where('doctor_id', $doctor->id)
                ->orderBy('day_of_week')
                ->get();

            $this->assertCount(7, $availability);
            $this->assertTrue($availability->every(fn (DoctorAvailability $day): bool => $day->is_active));
        }
    }

    public function test_each_tenant_home_and_chat_page_uses_its_own_services_and_chatbot_name(): void
    {
        $this->seed([HospitalSeeder::class, DoctorUserSeeder::class]);

        $this->get('/h/pearl')
            ->assertSee('Pearl Hospital')
            ->assertSee('Dental Checkup & Cleaning')
            ->assertSee('KSh 15,000.00')
            ->assertDontSee('Paediatric Consultation');

        $this->get('/h/pearl/chat')
            ->assertSee('Pearlie AI Assistant')
            ->assertSee('Dental Checkup & Cleaning (30 min)')
            ->assertSee('Renal Dialysis Session (240 min)')
            ->assertDontSee('KSh')
            ->assertDontSee('Paediatric Consultation');

        $this->get('/h/demo')
            ->assertSee('Nairobi Medical Centre')
            ->assertSee('Paediatric Consultation')
            ->assertSee('KSh 1,800.00')
            ->assertDontSee('Renal Dialysis Session');

        $this->get('/h/demo/chat')
            ->assertSee('Nairobie AI Assistant')
            ->assertSee('Paediatric Consultation (30 min)')
            ->assertDontSee('KSh')
            ->assertDontSee('Renal Dialysis Session');
    }
}
