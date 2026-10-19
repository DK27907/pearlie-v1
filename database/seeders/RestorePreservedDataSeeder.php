<?php

namespace Database\Seeders;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class RestorePreservedDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $hospital = Hospital::withoutGlobalScopes()
            ->where('id', 1)
            ->where('slug', 'pearl')
            ->firstOrFail();
        $previousHospital = app()->bound('currentHospital') ? hospital() : null;
        app()->instance('currentHospital', $hospital);

        try {
            $patient = $hospital->users()->updateOrCreate(
                ['email' => 'patient@pearl.test'],
                [
                    'name' => 'Patricia Wanjiku',
                    'role' => 'patient',
                    'phone' => '254748249882',
                    'password' => 'password',
                    'email_verified_at' => now(),
                    'is_active' => true,
                    'is_admin' => false,
                    'is_super_admin' => false,
                    'is_doctor' => false,
                ],
            );
            $patient->syncRolesFromFlags();
            $doctorOne = $hospital->users()->where('email', 'doctor1@pearl.test')->firstOrFail();
            $doctorTwo = $hospital->users()->where('email', 'doctor2@pearl.test')->firstOrFail();
            $dentalService = $hospital->services()
                ->where('name', 'Dental Checkup & Cleaning')
                ->firstOrFail();
            $generalService = $hospital->services()
                ->where('name', 'General Consultation')
                ->firstOrFail();
            $appointmentDate = now()->next(Carbon::MONDAY)->toDateString();

            Model::unguarded(function () use (
                $appointmentDate,
                $dentalService,
                $doctorOne,
                $doctorTwo,
                $generalService,
                $hospital,
                $patient,
            ): void {
                $hospital->appointments()->updateOrCreate(
                    ['session_id' => 'browser-smoke-pending'],
                    [
                        'id' => 14,
                        'patient_id' => $patient->id,
                        'name' => $patient->name,
                        'phone' => '254748249882',
                        'mpesa_phone' => '254748249882',
                        'email' => $patient->email,
                        'preferred_date' => $appointmentDate,
                        'reason' => 'Dental cleaning browser smoke test',
                        'raw_message' => 'Browser smoke-test appointment: pending.',
                        'status' => AppointmentRequest::STATUS_PENDING,
                        'booking_fee' => 2500,
                        'payment_amount' => 2500,
                        'payment_status' => 'pending',
                        'doctor_id' => $doctorOne->id,
                        'service_id' => $dentalService->id,
                        'slot_start_time' => '09:00',
                        'slot_end_time' => '09:30',
                        'status_updated_at' => now(),
                    ],
                );

                $hospital->appointments()->updateOrCreate(
                    ['session_id' => 'browser-smoke-confirmed'],
                    [
                        'id' => 15,
                        'patient_id' => $patient->id,
                        'name' => $patient->name,
                        'phone' => '254748249882',
                        'mpesa_phone' => '254748249882',
                        'email' => $patient->email,
                        'preferred_date' => $appointmentDate,
                        'reason' => 'General consultation browser smoke test',
                        'raw_message' => 'Browser smoke-test appointment: confirmed.',
                        'status' => AppointmentRequest::STATUS_CONFIRMED,
                        'booking_fee' => 1500,
                        'payment_amount' => 1500,
                        'payment_status' => 'paid',
                        'paid_at' => now(),
                        'doctor_id' => $doctorTwo->id,
                        'service_id' => $generalService->id,
                        'slot_start_time' => '10:00',
                        'slot_end_time' => '10:30',
                        'status_updated_at' => now(),
                    ],
                );
            });
        } finally {
            if ($previousHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }
}
