<?php

namespace Database\Seeders;

use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PearlDoctorAvailabilitySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'Pearl sample doctor availability belongs to the unverified .test clinician roster and cannot be seeded in production.',
            );
        }

        $hospital = Hospital::withoutGlobalScopes()->where('slug', 'pearl')->firstOrFail();
        $doctorEmails = array_column(PearlHospitalFullServicesSeeder::DOCTORS, 'email');
        $doctors = User::withoutGlobalScopes()
            ->where('hospital_id', $hospital->id)
            ->whereIn('email', $doctorEmails)
            ->where('is_doctor', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($doctors->count() !== count($doctorEmails)) {
            throw new RuntimeException('Seed the Pearl sample clinician catalog before creating its availability.');
        }

        DB::transaction(function () use ($hospital, $doctors): void {
            foreach ($doctors as $doctor) {
                foreach (range(1, 5) as $dayOfWeek) {
                    $availability = DoctorAvailability::withoutGlobalScope('hospital')->updateOrCreate(
                        [
                            'doctor_id' => $doctor->id,
                            'day_of_week' => $dayOfWeek,
                        ],
                        [
                            'start_time' => '09:00',
                            'end_time' => '17:00',
                            'slot_duration_minutes' => $hospital->slot_duration_minutes,
                            'max_patients_per_slot' => 1,
                            'is_active' => true,
                        ],
                    );

                    $availability->forceFill(['hospital_id' => $hospital->id])->save();
                }
            }
        });
    }
}
