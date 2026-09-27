<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
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
            'pearl' => config('pearlie.doctors.seed_accounts', []),
            'demo' => [
                [
                    'name' => 'Dr. John Kamau',
                    'email' => 'doctor1@demohospital.co.ke',
                    'specialization' => 'General Medicine',
                    'phone' => '0700000011',
                ],
                [
                    'name' => 'Dr. Mary Wanjiru',
                    'email' => 'doctor2@demohospital.co.ke',
                    'specialization' => 'Pediatrics',
                    'phone' => '0700000012',
                ],
            ],
        ];

        try {
            foreach ($accounts as $slug => $doctors) {
                $hospital = \App\Models\Hospital::query()->where('slug', $slug)->firstOrFail();
                app()->instance('currentHospital', $hospital);

                foreach ($doctors as $doctorData) {
                    $doctor = User::query()->updateOrCreate(
                        ['email' => $doctorData['email']],
                        [...$doctorData, 'password' => 'password', 'is_doctor' => true, 'role' => 'doctor'],
                    );

                    foreach (config('pearlie.appointment.default_schedule.days', []) as $dayOfWeek) {
                        $doctor->availabilities()->updateOrCreate(
                            ['day_of_week' => $dayOfWeek],
                            [
                                'start_time' => config('pearlie.appointment.default_schedule.start_time'),
                                'end_time' => config('pearlie.appointment.default_schedule.end_time'),
                                'slot_duration_minutes' => $hospital->slot_duration_minutes,
                                'max_patients_per_slot' => 1,
                                'is_active' => true,
                            ],
                        );
                    }
                }
            }
        } finally {
            app()->instance('currentHospital', $previousHospital);
        }
    }
}
