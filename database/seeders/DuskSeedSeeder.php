<?php

namespace Database\Seeders;

use App\Models\AppointmentRequest;
use App\Models\Escalation;
use App\Models\Hospital;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

class DuskSeedSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Browser smoke-test accounts must not be seeded in production.');
        }

        $this->call([HospitalSeeder::class, DoctorUserSeeder::class]);

        $hospital = Hospital::withoutGlobalScopes()->where('slug', 'pearl')->firstOrFail();
        $previousHospital = app()->bound('currentHospital') ? hospital() : null;
        app()->instance('currentHospital', $hospital);

        try {
            $hospital->forceFill([
                'name' => 'Pearl Hospital',
                'chatbot_name' => 'Pearlie',
                'subscription_plan' => 'enterprise',
                'subscription_status' => 'active',
                'is_active' => true,
            ])->save();

            $this->user($hospital, 'superadmin@pearl.test', [
                'name' => 'Platform Administrator',
                'role' => 'super_admin',
                'is_super_admin' => true,
                'is_admin' => false,
                'is_doctor' => false,
            ]);
            $admin = $this->user($hospital, 'admin@pearl.test', [
                'name' => 'Pearl Hospital Admin',
                'role' => 'hospital_admin',
                'is_admin' => true,
                'is_super_admin' => false,
                'is_doctor' => false,
            ]);
            $doctorOne = $this->user($hospital, 'doctor1@pearl.test', [
                'name' => 'Dr. Amina Njeri',
                'role' => 'doctor',
                'is_doctor' => true,
                'is_admin' => false,
                'is_super_admin' => false,
                'specialization' => 'Dentist',
                'consultation_fee' => 2500,
                'licence_number' => 'KMPDC-12345',
                'bio' => 'Provides general dental care and preventive dentistry.',
            ]);
            $doctorTwo = $this->user($hospital, 'doctor2@pearl.test', [
                'name' => 'Dr. Brian Otieno',
                'role' => 'doctor',
                'is_doctor' => true,
                'is_admin' => false,
                'is_super_admin' => false,
                'specialization' => 'General Practitioner',
                'consultation_fee' => 1500,
                'licence_number' => 'KMPDC-67890',
                'bio' => 'Provides primary care and general consultations.',
            ]);
            $patient = $this->user($hospital, 'patient@pearl.test', [
                'name' => 'Patricia Wanjiku',
                'role' => 'patient',
                'is_admin' => false,
                'is_super_admin' => false,
                'is_doctor' => false,
                'phone' => '254748249882',
            ]);

            $services = $hospital->services()
                ->active()
                ->orderBy('name')
                ->get()
                ->keyBy('name');

            $appointmentDate = CarbonImmutable::now()->next(CarbonImmutable::MONDAY)->toDateString();
            $hospital->appointments()->updateOrCreate(
                ['session_id' => 'browser-smoke-pending'],
                [
                    'patient_id' => $patient->id,
                    'name' => $patient->name,
                    'phone' => '254748249882',
                    'mpesa_phone' => '254748249882',
                    'email' => $patient->email,
                    'preferred_date' => $appointmentDate,
                    'reason' => 'Dental checkup browser smoke test',
                    'raw_message' => 'Browser smoke-test appointment: pending.',
                    'status' => AppointmentRequest::STATUS_PENDING,
                    'booking_fee' => 2500,
                    'payment_amount' => 2500,
                    'payment_status' => 'pending',
                    'doctor_id' => $doctorOne->id,
                    'service_id' => $services['Dental Checkup & Cleaning']->id,
                    'slot_start_time' => '09:00',
                    'slot_end_time' => '09:30',
                    'status_updated_at' => now(),
                ],
            );
            $hospital->appointments()->updateOrCreate(
                ['session_id' => 'browser-smoke-confirmed'],
                [
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
                    'service_id' => $services['General Consultation']->id,
                    'slot_start_time' => '10:00',
                    'slot_end_time' => '10:30',
                    'status_updated_at' => now(),
                ],
            );

            Escalation::query()->updateOrCreate(
                ['session_id' => 'browser-smoke-pending'],
                [
                    'user_message' => 'Please have a clinician follow up about my appointment.',
                    'user_phone' => '254700000101',
                    'ai_response' => 'A team member will follow up shortly.',
                    'status' => Escalation::STATUS_PENDING,
                    'assigned_worker_id' => $doctorOne->id,
                ],
            );

            $hospital->knowledgeBases()->updateOrCreate(
                [
                    'category' => 'services',
                    'question' => 'What services are available for browser testing?',
                ],
                [
                    'subcategory' => 'browser-smoke',
                    'keywords' => ['browser', 'services', 'smoke test'],
                    'answer' => implode(', ', $services->keys()->all()).' are available.',
                    'source' => 'Browser smoke-test seed data',
                    'last_updated' => today(),
                    'approved_by' => $admin->name,
                ],
            );
        } finally {
            if ($previousHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(Hospital $hospital, string $email, array $attributes): User
    {
        return User::withoutGlobalScopes()->updateOrCreate(
            ['email' => $email],
            [
                ...$attributes,
                'hospital_id' => $hospital->id,
                'email_verified_at' => now(),
                'password' => 'password',
                'is_active' => true,
            ],
        );
    }
}
