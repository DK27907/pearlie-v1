<?php

namespace Database\Seeders;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PearlHospitalFullServicesSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, email: string, specialization: string}>
     */
    public const DOCTORS = [
        ['name' => 'Dr. Faith Mutua', 'email' => 'pearl-catalog-pediatrics@pearl.test', 'specialization' => 'Pediatrician'],
        ['name' => 'Dr. Samuel Kiptoo', 'email' => 'pearl-catalog-oncology@pearl.test', 'specialization' => 'Oncologist'],
        ['name' => 'Dr. Mercy Wambui', 'email' => 'pearl-catalog-neurology@pearl.test', 'specialization' => 'Neurologist'],
        ['name' => 'Dr. Esther Chebet', 'email' => 'pearl-catalog-psychiatry@pearl.test', 'specialization' => 'Psychiatrist'],
        ['name' => 'Dr. Susan Njoki', 'email' => 'pearl-catalog-urology@pearl.test', 'specialization' => 'Urologist'],
        ['name' => 'Dr. Robert Mwangi', 'email' => 'pearl-catalog-orthopedics@pearl.test', 'specialization' => 'Orthopedic Surgeon'],
        ['name' => 'Dr. Catherine Achieng', 'email' => 'pearl-catalog-ent@pearl.test', 'specialization' => 'ENT Specialist'],
        ['name' => 'Dr. Daniel Owino', 'email' => 'pearl-catalog-cardiology@pearl.test', 'specialization' => 'Cardiologist'],
        ['name' => 'Dr. Peter Kimani', 'email' => 'pearl-catalog-gastroenterology@pearl.test', 'specialization' => 'Gastroenterologist'],
        ['name' => 'Dr. Paul Njoroge', 'email' => 'pearl-catalog-surgery@pearl.test', 'specialization' => 'Surgeon'],
        ['name' => 'Dr. Winnie Wambui', 'email' => 'pearl-catalog-fertility@pearl.test', 'specialization' => 'Fertility Specialist'],
        ['name' => 'Dr. John Njoroge', 'email' => 'pearl-catalog-nephrology@pearl.test', 'specialization' => 'Nephrologist'],
        ['name' => 'Dr. Mary Wanjiru', 'email' => 'pearl-catalog-optometry@pearl.test', 'specialization' => 'Optometrist'],
        ['name' => 'Dr. David Mwangi', 'email' => 'pearl-catalog-occupational-therapy@pearl.test', 'specialization' => 'Occupational Therapist'],
        ['name' => 'Dr. Lucy Cherono', 'email' => 'pearl-catalog-pharmacy@pearl.test', 'specialization' => 'Pharmacist'],
    ];

    /**
     * @var array<int, array{name: string, price: int, category: string, requires_specialty: ?string}>
     */
    public const SERVICES = [
        ['name' => 'Outpatient Consultation', 'price' => 500, 'category' => 'General & Emergency', 'requires_specialty' => null],
        ['name' => 'Inpatient Admission', 'price' => 5000, 'category' => 'General & Emergency', 'requires_specialty' => null],
        ['name' => 'Executive Private Ward', 'price' => 15000, 'category' => 'General & Emergency', 'requires_specialty' => null],
        ['name' => 'Emergency Ambulance Service', 'price' => 3000, 'category' => 'General & Emergency', 'requires_specialty' => null],
        ['name' => 'Gynecology & Obstetrics Consultation', 'price' => 2500, 'category' => 'Specialist Clinic', 'requires_specialty' => 'Gynecologist'],
        ['name' => 'Pediatric Consultation', 'price' => 2000, 'category' => 'Specialist Clinic', 'requires_specialty' => 'Pediatrician'],
        ['name' => 'Oncology Consultation', 'price' => 5000, 'category' => 'Specialist Clinic', 'requires_specialty' => 'Oncologist'],
        ['name' => 'Neurology Consultation', 'price' => 4500, 'category' => 'Specialist Clinic', 'requires_specialty' => 'Neurologist'],
        ['name' => 'Psychiatry Consultation', 'price' => 4000, 'category' => 'Specialist Clinic', 'requires_specialty' => 'Psychiatrist'],
        ['name' => 'Urology Consultation', 'price' => 3500, 'category' => 'Specialist Clinic', 'requires_specialty' => 'Urologist'],
        ['name' => 'Orthopedic Consultation', 'price' => 3500, 'category' => 'Specialist Clinic', 'requires_specialty' => 'Orthopedic Surgeon'],
        ['name' => 'ENT Consultation', 'price' => 3000, 'category' => 'Specialist Clinic', 'requires_specialty' => 'ENT Specialist'],
        ['name' => 'Maternity Package', 'price' => 40000, 'category' => 'Reproductive Health', 'requires_specialty' => 'Obstetrician'],
        ['name' => 'IVF & Fertility Consultation', 'price' => 8000, 'category' => 'Reproductive Health', 'requires_specialty' => 'Fertility Specialist'],
        ['name' => 'Well Baby Clinic Visit', 'price' => 1500, 'category' => 'Reproductive Health', 'requires_specialty' => 'Pediatrician'],
        ['name' => 'Family Planning Consultation', 'price' => 1000, 'category' => 'Reproductive Health', 'requires_specialty' => 'Gynecologist'],
        ['name' => 'CT Scan', 'price' => 8000, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => 'Radiologist'],
        ['name' => 'Digital X-Ray', 'price' => 3000, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => 'Radiologist'],
        ['name' => 'Fluoroscopy Study', 'price' => 6000, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => 'Radiologist'],
        ['name' => 'Ultrasound Scan', 'price' => 2500, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => 'Radiologist'],
        ['name' => 'Doppler Ultrasound', 'price' => 4000, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => 'Radiologist'],
        ['name' => 'EEG (Brain Activity)', 'price' => 5000, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => 'Neurologist'],
        ['name' => 'ECG (Electrocardiography)', 'price' => 2500, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => 'Cardiologist'],
        ['name' => 'Echocardiography (ECHO)', 'price' => 6000, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => 'Cardiologist'],
        ['name' => 'Endoscopy', 'price' => 12000, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => 'Gastroenterologist'],
        ['name' => 'Colonoscopy', 'price' => 15000, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => 'Gastroenterologist'],
        ['name' => 'Ultrasound-Guided Biopsy', 'price' => 10000, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => 'Radiologist'],
        ['name' => 'CT-Guided Biopsy', 'price' => 14000, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => 'Radiologist'],
        ['name' => 'Minor Surgery', 'price' => 15000, 'category' => 'Surgical', 'requires_specialty' => 'Surgeon'],
        ['name' => 'Major Surgery', 'price' => 60000, 'category' => 'Surgical', 'requires_specialty' => 'Surgeon'],
        ['name' => 'Chemotherapy Session', 'price' => 25000, 'category' => 'Cancer Care', 'requires_specialty' => 'Oncologist'],
        ['name' => 'Palliative Care Consultation', 'price' => 5000, 'category' => 'Cancer Care', 'requires_specialty' => 'Oncologist'],
        ['name' => 'Renal Dialysis Session', 'price' => 12000, 'category' => 'Specialty Unit', 'requires_specialty' => 'Nephrologist'],
        ['name' => 'Dental Checkup', 'price' => 1, 'category' => 'Allied Health', 'requires_specialty' => 'Dentist'],
        ['name' => 'Dental Cleaning', 'price' => 1, 'category' => 'Allied Health', 'requires_specialty' => 'Dentist'],
        ['name' => 'Dental Restoration', 'price' => 8000, 'category' => 'Allied Health', 'requires_specialty' => 'Dentist'],
        ['name' => 'Optical Eye Checkup', 'price' => 1500, 'category' => 'Allied Health', 'requires_specialty' => 'Optometrist'],
        ['name' => 'Family Physiotherapy Session', 'price' => 2000, 'category' => 'Allied Health', 'requires_specialty' => 'Physiotherapist'],
        ['name' => 'Occupational Therapy Session', 'price' => 2500, 'category' => 'Allied Health', 'requires_specialty' => 'Occupational Therapist'],
        ['name' => 'Well-Woman Health Screening', 'price' => 8000, 'category' => 'Allied Health', 'requires_specialty' => 'Gynecologist'],
        ['name' => 'Well-Man Health Screening', 'price' => 8000, 'category' => 'Allied Health', 'requires_specialty' => 'General Practitioner'],
        ['name' => 'Pharmacy Consultation', 'price' => 500, 'category' => 'Allied Health', 'requires_specialty' => 'Pharmacist'],
        ['name' => 'Laboratory Tests', 'price' => 500, 'category' => 'Diagnostics & Imaging', 'requires_specialty' => null],
        ['name' => 'Antenatal Clinic Visit', 'price' => 500, 'category' => 'Reproductive Health', 'requires_specialty' => 'Obstetrician'],
        ['name' => 'General Consultation', 'price' => 500, 'category' => 'General & Emergency', 'requires_specialty' => 'General Practitioner'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'Pearl sample catalog contains unverified prices, KES 1 test services, and sample .test clinician accounts; it cannot be seeded in production.',
            );
        }

        $hospital = Hospital::withoutGlobalScopes()->where('slug', 'pearl')->firstOrFail();
        $hadCurrentHospital = app()->bound('currentHospital');
        $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;

        try {
            app()->instance('currentHospital', $hospital);

            DB::transaction(function () use ($hospital): void {
                foreach (self::SERVICES as $service) {
                    $serviceModel = $hospital->services()->firstOrNew(['name' => $service['name']]);
                    if (! $serviceModel->exists) {
                        $serviceModel->duration_minutes = 30;
                    }
                    $serviceModel->fill([
                        'description' => $service['name'].' at Pearl Hospital.',
                        'price' => $service['price'],
                        'is_active' => true,
                        'category' => $service['category'],
                        'requires_specialty' => $service['requires_specialty'],
                    ])->save();
                }

                foreach (self::DOCTORS as $doctorData) {
                    $doctor = User::withoutGlobalScopes()
                        ->where('email', $doctorData['email'])
                        ->first();

                    if ($doctor !== null && (int) $doctor->hospital_id !== (int) $hospital->id) {
                        throw new RuntimeException('A sample Pearl clinician email is already assigned to another hospital.');
                    }

                    if ($doctor === null) {
                        $doctor = new User;
                        $doctor->forceFill([
                            'email' => $doctorData['email'],
                            'password' => Str::random(64),
                        ]);
                    }

                    $doctor->forceFill([
                        'name' => $doctorData['name'],
                        'hospital_id' => $hospital->id,
                        'role' => 'doctor',
                        'is_doctor' => true,
                        'is_admin' => false,
                        'is_super_admin' => false,
                        'is_active' => true,
                        'specialization' => $doctorData['specialization'],
                        'email_verified_at' => now(),
                        'must_change_password' => true,
                    ])->save();

                    $doctor->syncRolesFromFlags();
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
}
