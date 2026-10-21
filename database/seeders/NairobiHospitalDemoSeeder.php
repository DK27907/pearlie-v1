<?php

namespace Database\Seeders;

use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\KnowledgeBase;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class NairobiHospitalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $hadCurrentHospital = app()->bound('currentHospital');
        $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;

        try {
            DB::transaction(function (): void {
                $hospital = Hospital::withoutGlobalScopes()->firstOrCreate(
                    ['slug' => 'nairobi-hospital'],
                    [
                        'name' => 'Nairobi Hospital',
                        'primary_color' => '#0a2f44',
                        'secondary_color' => '#1a5276',
                        'site_header_text' => 'Nairobi Hospital',
                        'site_footer_text' => '© 2026 Nairobi Hospital. All rights reserved.',
                        'chatbot_name' => 'Nairobi',
                        'address' => 'Argwings Kodhek Road, Nairobi',
                        'city' => 'Nairobi',
                        'county' => 'Nairobi',
                        'phone' => '+254700111222',
                        'emergency_phone' => '+254700111222',
                        'email' => 'info@nairobi-hospital.test',
                        'website' => 'https://nairobi-hospital.test',
                        'deposit_amount' => 500,
                        'slot_duration_minutes' => 30,
                        'no_show_grace_minutes' => 30,
                        'hours_emergency' => '24/7',
                        'hours_outpatient' => '9:00 AM - 5:00 PM, Mon-Fri',
                        'default_language' => 'en',
                        'supported_languages' => ['en', 'sw'],
                        'subscription_plan' => 'enterprise',
                        'subscription_status' => 'active',
                        'is_active' => true,
                        'auto_confirm_paid_appointments' => true,
                        'settings' => ['auto_confirm_paid_appointments' => true],
                    ],
                );

                $hospital->forceFill([
                    'name' => 'Nairobi Hospital',
                    'subscription_plan' => 'enterprise',
                    'subscription_status' => 'active',
                    'is_active' => true,
                ])->save();

                app()->instance('currentHospital', $hospital);

                $admin = User::withoutGlobalScopes()->firstOrCreate(
                    ['email' => 'admin@nairobi-hospital.test'],
                    [
                        'name' => 'Nairobi Hospital Admin',
                        'password' => 'password@2026',
                        'hospital_id' => $hospital->id,
                        'role' => 'hospital_admin',
                        'is_admin' => true,
                        'is_doctor' => false,
                        'is_super_admin' => false,
                        'is_active' => true,
                        'email_verified_at' => now(),
                        'must_change_password' => true,
                    ],
                );

                $this->assertBelongsToHospital($admin, $hospital);
                $admin->forceFill([
                    'name' => 'Nairobi Hospital Admin',
                    'role' => 'hospital_admin',
                    'is_admin' => true,
                    'is_doctor' => false,
                    'is_super_admin' => false,
                    'is_active' => true,
                    'must_change_password' => true,
                ])->save();
                $admin->syncRolesFromFlags();

                $services = [
                    [
                        'name' => 'General Consultation',
                        'description' => 'Consultation with a general practitioner.',
                        'category' => 'General',
                        'duration_minutes' => 30,
                        'price' => 1500,
                        'requires_specialty' => 'General Practitioner',
                    ],
                    [
                        'name' => 'Dental Checkup',
                        'description' => 'Routine dental examination.',
                        'category' => 'Dental',
                        'duration_minutes' => 30,
                        'price' => 1,
                        'requires_specialty' => 'Dentist',
                    ],
                    [
                        'name' => 'Dental Cleaning',
                        'description' => 'Professional dental cleaning.',
                        'category' => 'Dental',
                        'duration_minutes' => 30,
                        'price' => 1,
                        'requires_specialty' => 'Dentist',
                    ],
                    [
                        'name' => 'Optical Eye Checkup',
                        'description' => 'Eye health and vision assessment.',
                        'category' => 'Optical',
                        'duration_minutes' => 30,
                        'price' => 1500,
                        'requires_specialty' => 'Optometrist',
                    ],
                    [
                        'name' => 'Ultrasound Scan (Obstetric)',
                        'description' => 'Obstetric ultrasound imaging appointment.',
                        'category' => 'Diagnostic',
                        'duration_minutes' => 30,
                        'price' => 1000,
                        'requires_specialty' => 'Radiologist',
                    ],
                    [
                        'name' => 'CT Scan (Head/Chest/Abdomen)',
                        'description' => 'Computed tomography scan appointment.',
                        'category' => 'Diagnostic',
                        'duration_minutes' => 45,
                        'price' => 12000,
                        'requires_specialty' => 'Radiologist',
                    ],
                    [
                        'name' => 'Digital X-Ray',
                        'description' => 'Digital X-ray imaging appointment.',
                        'category' => 'Diagnostic',
                        'duration_minutes' => 20,
                        'price' => 3000,
                        'requires_specialty' => 'Radiologist',
                    ],
                    [
                        'name' => 'Physiotherapy Session',
                        'description' => 'One-on-one physiotherapy treatment session.',
                        'category' => 'Physiotherapy',
                        'duration_minutes' => 45,
                        'price' => 2000,
                        'requires_specialty' => 'Physiotherapist',
                    ],
                    [
                        'name' => 'Antenatal Clinic Visit',
                        'description' => 'Routine antenatal clinic consultation.',
                        'category' => 'Maternity',
                        'duration_minutes' => 30,
                        'price' => 1000,
                        'requires_specialty' => 'Obstetrician/Gynecologist',
                    ],
                    [
                        'name' => 'Renal Dialysis Session',
                        'description' => 'Scheduled renal dialysis treatment session.',
                        'category' => 'Renal',
                        'duration_minutes' => 240,
                        'price' => 15000,
                        'requires_specialty' => 'Nephrologist',
                    ],
                    [
                        'name' => 'Oncology Consultation',
                        'description' => 'Consultation with an oncology specialist.',
                        'category' => 'Oncology',
                        'duration_minutes' => 45,
                        'price' => 5000,
                        'requires_specialty' => 'Oncologist',
                    ],
                ];

                foreach ($services as $serviceData) {
                    $service = Service::withoutGlobalScopes()->updateOrCreate(
                        [
                            'hospital_id' => $hospital->id,
                            'name' => $serviceData['name'],
                        ],
                        [...$serviceData, 'is_active' => true],
                    );

                    $service->forceFill(['hospital_id' => $hospital->id])->save();
                }

                $doctors = [
                    [
                        'name' => 'Dr. Amina Njeri',
                        'email' => 'amina.njeri@nairobi-hospital.test',
                        'specialization' => 'Dentist',
                        'phone' => '+254700111223',
                        'consultation_fee' => 1500,
                        'licence_number' => 'KMPDC-NH-001',
                        'bio' => 'Provides general dental care and preventive dentistry.',
                    ],
                    [
                        'name' => 'Dr. James Ochieng',
                        'email' => 'james.ochieng@nairobi-hospital.test',
                        'specialization' => 'General Practitioner',
                        'phone' => '+254700111224',
                        'consultation_fee' => 1500,
                        'licence_number' => 'KMPDC-NH-002',
                        'bio' => 'Provides primary care and general consultations.',
                    ],
                    [
                        'name' => 'Dr. Grace Wanjiku',
                        'email' => 'grace.wanjiku@nairobi-hospital.test',
                        'specialization' => 'Obstetrician/Gynecologist',
                        'phone' => '+254700111225',
                        'consultation_fee' => 3000,
                        'licence_number' => 'KMPDC-NH-003',
                        'bio' => 'Provides maternity and women’s health care.',
                    ],
                    [
                        'name' => 'Dr. Peter Kimani',
                        'email' => 'peter.kimani@nairobi-hospital.test',
                        'specialization' => 'Radiologist',
                        'phone' => '+254700111226',
                        'consultation_fee' => 2500,
                        'licence_number' => 'KMPDC-NH-004',
                        'bio' => 'Provides diagnostic imaging services.',
                    ],
                    [
                        'name' => 'Dr. Faith Mutua',
                        'email' => 'faith.mutua@nairobi-hospital.test',
                        'specialization' => 'Pediatrician',
                        'phone' => '+254700111227',
                        'consultation_fee' => 2000,
                        'licence_number' => 'KMPDC-NH-005',
                        'bio' => 'Provides paediatric and child health care.',
                    ],
                    [
                        'name' => 'Dr. Samuel Kiptoo',
                        'email' => 'samuel.kiptoo@nairobi-hospital.test',
                        'specialization' => 'Cardiologist',
                        'phone' => '+254700111228',
                        'consultation_fee' => 2500,
                        'licence_number' => 'KMPDC-NH-006',
                        'bio' => 'Provides cardiovascular assessment and treatment.',
                    ],
                ];

                foreach ($doctors as $doctorData) {
                    $doctor = User::withoutGlobalScopes()->firstOrCreate(
                        ['email' => $doctorData['email']],
                        [
                            ...$doctorData,
                            'password' => 'password@2026',
                            'hospital_id' => $hospital->id,
                            'role' => 'doctor',
                            'is_admin' => false,
                            'is_doctor' => true,
                            'is_super_admin' => false,
                            'is_active' => true,
                            'email_verified_at' => now(),
                            'must_change_password' => true,
                        ],
                    );

                    $this->assertBelongsToHospital($doctor, $hospital);
                    $doctor->forceFill([
                        ...$doctorData,
                        'role' => 'doctor',
                        'is_admin' => false,
                        'is_doctor' => true,
                        'is_super_admin' => false,
                        'is_active' => true,
                        'must_change_password' => true,
                    ])->save();
                    $doctor->syncRolesFromFlags();

                    foreach (range(1, 5) as $dayOfWeek) {
                        $availability = DoctorAvailability::withoutGlobalScopes()->updateOrCreate(
                            [
                                'hospital_id' => $hospital->id,
                                'doctor_id' => $doctor->id,
                                'day_of_week' => $dayOfWeek,
                            ],
                            [
                                'start_time' => '09:00',
                                'end_time' => '17:00',
                                'slot_duration_minutes' => (int) $hospital->slot_duration_minutes,
                                'max_patients_per_slot' => 1,
                                'is_active' => true,
                            ],
                        );
                        $availability->forceFill(['hospital_id' => $hospital->id])->save();
                    }
                }

                foreach ($services as $service) {
                    $serviceSlug = Str::slug($service['name']);
                    KnowledgeBase::withoutGlobalScopes()->updateOrCreate(
                        [
                            'hospital_id' => $hospital->id,
                            'category' => 'services',
                            'subcategory' => 'service-'.$serviceSlug,
                        ],
                        [
                            'keywords' => [
                                $service['name'],
                                Str::lower($service['name']),
                                'cost',
                                'price',
                                'fee',
                            ],
                            'question' => 'How much does '.$service['name'].' cost?',
                            'answer' => $service['name'].' at Nairobi Hospital costs KES '
                                .number_format((float) $service['price'], 2, '.', ',').'.',
                            'source' => 'Nairobi Hospital Administration',
                            'last_updated' => now(),
                        ],
                    );
                }
            });
        } finally {
            if ($hadCurrentHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }

    private function assertBelongsToHospital(User $user, Hospital $hospital): void
    {
        if ((int) $user->hospital_id !== (int) $hospital->id) {
            throw new RuntimeException(
                "The demo account {$user->email} already belongs to another hospital.",
            );
        }
    }
}
