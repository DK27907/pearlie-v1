<?php

namespace Database\Seeders;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DoctorUserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DoctorUserSeeder creates sample accounts with development passwords and cannot run in production.');
        }

        $previousHospital = hospital();
        $accounts = [
            'pearl' => [
                [
                    'name' => 'Dr. Amina Njeri',
                    'email' => 'doctor1@pearl.test',
                    'specialization' => 'Dentist',
                    'phone' => '0700000001',
                    'consultation_fee' => 2500,
                    'licence_number' => 'KMPDC-12345',
                    'bio' => 'Provides general dental care and preventive dentistry.',
                ],
                [
                    'name' => 'Dr. Brian Otieno',
                    'email' => 'doctor2@pearl.test',
                    'specialization' => 'General Practitioner',
                    'phone' => '0700000002',
                    'consultation_fee' => 1500,
                    'licence_number' => 'KMPDC-67890',
                    'bio' => 'Provides primary care and general consultations.',
                ],
                [
                    'name' => 'Dr. Grace Wanjiru',
                    'email' => 'doctor3@pearl.test',
                    'specialization' => 'Obstetrician/Gynecologist',
                    'phone' => '0700000003',
                    'consultation_fee' => 3000,
                    'licence_number' => 'KMPDC-23456',
                    'bio' => "Provides maternity and women's health care.",
                ],
                [
                    'name' => 'Dr. Samuel Kipchoge',
                    'email' => 'doctor4@pearl.test',
                    'specialization' => 'Radiologist',
                    'phone' => '0700000004',
                    'consultation_fee' => 2500,
                    'licence_number' => 'KMPDC-34567',
                    'bio' => 'Provides diagnostic imaging services.',
                ],
                [
                    'name' => 'Dr. Lydia Achieng',
                    'email' => 'doctor5@pearl.test',
                    'specialization' => 'Physiotherapist',
                    'phone' => '0700000005',
                    'consultation_fee' => 2000,
                    'licence_number' => 'KMPDC-45678',
                    'bio' => 'Provides physiotherapy and rehabilitation care.',
                ],
            ],
            'demo' => [
                [
                    'name' => 'Dr. John Kamau',
                    'email' => 'doctor1@demohospital.co.ke',
                    'specialization' => 'General Practitioner',
                    'phone' => '0700000011',
                    'consultation_fee' => 1200,
                    'licence_number' => 'KMPDC-DEMO-001',
                    'bio' => 'Provides primary care and general consultations.',
                ],
                [
                    'name' => 'Dr. Mary Wanjiru',
                    'email' => 'doctor2@demohospital.co.ke',
                    'specialization' => 'Paediatrician',
                    'phone' => '0700000012',
                    'consultation_fee' => 1800,
                    'licence_number' => 'KMPDC-DEMO-002',
                    'bio' => 'Provides paediatric and child health care.',
                ],
            ],
        ];

        try {
            foreach ($accounts as $slug => $doctors) {
                $hospital = Hospital::query()->where('slug', $slug)->firstOrFail();
                app()->instance('currentHospital', $hospital);
                $seededDoctors = [];

                if ($slug === 'pearl') {
                    $legacyEmails = collect(config('pearlie.doctors.seed_accounts', []))
                        ->pluck('email')
                        ->filter()
                        ->diff(collect($doctors)->pluck('email'))
                        ->all();

                    $hospital->doctors()
                        ->whereNotIn('email', collect($doctors)->pluck('email'))
                        ->where(function ($query) use ($legacyEmails): void {
                            $query->whereIn('email', $legacyEmails)
                                ->orWhere('email', 'like', 'doctor%@pearlhospital.co.ke');
                        })
                        ->update(['is_active' => false]);
                }

                foreach ($doctors as $doctorData) {
                    $doctor = $hospital->users()->updateOrCreate(
                        ['email' => $doctorData['email']],
                        [
                            ...$doctorData,
                            'password' => 'password',
                            'is_doctor' => true,
                            'is_admin' => false,
                            'is_super_admin' => false,
                            'is_active' => true,
                            'role' => 'doctor',
                        ],
                    );

                    $seededDoctors[] = $doctor;
                }

                $this->removeGenericDoctors($hospital, $doctors);
                $hospital->doctors()
                    ->whereIn('name', ['Doctor One', 'Doctor Two', 'Doctor A', 'Doctor B'])
                    ->update(['is_active' => false]);

                foreach ($seededDoctors as $doctor) {
                    foreach (range(0, 6) as $dayOfWeek) {
                        $isAvailable = $slug !== 'pearl' || $dayOfWeek !== 0;
                        $doctor->availabilities()->updateOrCreate(
                            ['day_of_week' => $dayOfWeek],
                            [
                                'start_time' => '08:00',
                                'end_time' => '17:00',
                                'slot_duration_minutes' => $hospital->slot_duration_minutes,
                                'max_patients_per_slot' => 1,
                                'is_active' => $isAvailable,
                            ],
                        );
                    }
                }
            }
        } finally {
            app()->instance('currentHospital', $previousHospital);
        }
    }

    /**
     * @param  array<int, array{name: string, email: string, specialization: string, phone: string, consultation_fee: int, licence_number: string, bio: string}>  $doctors
     */
    private function removeGenericDoctors(Hospital $hospital, array $doctors): void
    {
        $genericDoctors = User::query()
            ->where('hospital_id', $hospital->id)
            ->where('is_doctor', true)
            ->whereIn('name', ['Doctor One', 'Doctor Two', 'Doctor A', 'Doctor B'])
            ->get();

        foreach ($genericDoctors as $genericDoctor) {
            $doctorIndex = in_array($genericDoctor->name, ['Doctor One', 'Doctor A'], true) ? 0 : 1;
            $replacement = User::query()
                ->where('hospital_id', $hospital->id)
                ->where('email', $doctors[$doctorIndex]['email'])
                ->where('is_doctor', true)
                ->first();

            if (! $replacement || $replacement->is($genericDoctor)) {
                Log::warning('Generic seeded doctor could not be matched to a named replacement.', [
                    'hospital_id' => $hospital->id,
                    'doctor_id' => $genericDoctor->id,
                ]);

                continue;
            }

            DB::transaction(function () use ($genericDoctor, $replacement, $hospital): void {
                foreach ($genericDoctor->availabilities as $availability) {
                    $replacement->availabilities()->firstOrCreate(
                        ['day_of_week' => $availability->day_of_week],
                        [
                            'start_time' => $availability->start_time,
                            'end_time' => $availability->end_time,
                            'slot_duration_minutes' => $availability->slot_duration_minutes,
                            'max_patients_per_slot' => $availability->max_patients_per_slot,
                            'is_active' => $availability->is_active,
                        ],
                    );
                }

                DB::table('appointment_requests')
                    ->where('hospital_id', $hospital->id)
                    ->where('doctor_id', $genericDoctor->id)
                    ->update(['doctor_id' => $replacement->id]);
                DB::table('doctor_unavailable_dates')
                    ->where('hospital_id', $hospital->id)
                    ->where('doctor_id', $genericDoctor->id)
                    ->update(['doctor_id' => $replacement->id]);
                DB::table('doctor_invites')
                    ->where('hospital_id', $hospital->id)
                    ->where('doctor_id', $genericDoctor->id)
                    ->update(['doctor_id' => $replacement->id]);

                $genericDoctor->delete();
            });
        }
    }
}
