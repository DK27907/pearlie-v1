<?php

namespace Database\Seeders;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
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
                'auto_confirm_paid_appointments' => true,
                'settings' => ['auto_confirm_paid_appointments' => true],
                'is_active' => true,
            ])->save();

            $this->user($hospital, 'superadmin@pearl.test', [
                'name' => 'Platform Administrator',
                'role' => 'super_admin',
                'is_super_admin' => true,
                'is_admin' => false,
                'is_doctor' => false,
            ]);
            $this->user($hospital, 'admin@pearl.test', [
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
            Model::unguarded(function () use (
                $appointmentDate,
                $doctorOne,
                $doctorTwo,
                $hospital,
                $patient,
                $services,
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
                        'service_id' => $services['Dental Checkup & Cleaning']->id,
                        'slot_start_time' => '09:00',
                        'slot_end_time' => '09:30',
                        'status_updated_at' => now(),
                    ],
                );
                $confirmedAppointment = $hospital->appointments()->updateOrCreate(
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
                        'service_id' => $services['General Consultation']->id,
                        'slot_start_time' => '10:00',
                        'slot_end_time' => '10:30',
                        'status_updated_at' => now(),
                    ],
                );

                $payment = $hospital->mpesaPayments()->updateOrCreate(
                    ['mpesa_receipt' => 'UJ1DF8SEEP'],
                    [
                        'appointment_request_id' => $confirmedAppointment->id,
                        'checkout_request_id' => 'ws_CO_PRESERVED_UJ1DF8SEEP',
                        'merchant_request_id' => (string) Str::uuid(),
                        'phone' => '254712345678',
                        'amount' => 1,
                        'status' => MpesaPayment::STATUS_COMPLETED,
                        'result_code' => 0,
                        'result_description' => 'The service request is processed successfully.',
                        'processed_at' => now(),
                    ],
                );

                $payment->events()->updateOrCreate(
                    ['event' => 'payment_completed'],
                    [
                        'hospital_id' => $hospital->id,
                        'payload' => [
                            'receipt' => 'UJ1DF8SEEP',
                            'restored_at' => now()->toIso8601String(),
                        ],
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
