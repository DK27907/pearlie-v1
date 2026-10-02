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
                $hospital = Hospital::query()->where('slug', $slug)->firstOrFail();
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

                $this->removeGenericDoctors($hospital, $doctors);
            }
        } finally {
            app()->instance('currentHospital', $previousHospital);
        }
    }

    /**
     * @param  array<int, array{name: string, email: string, specialization: string, phone: string}>  $doctors
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
