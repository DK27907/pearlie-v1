<?php

namespace Database\Seeders;

use App\Models\Hospital;
use Illuminate\Database\Seeder;

class HospitalSeeder extends Seeder
{
    public function run(): void
    {
        $pearl = Hospital::query()->updateOrCreate(
            ['slug' => 'pearl'],
            [
                'name' => 'Pearl Hospital',
                'primary_color' => '#0a2f44',
                'secondary_color' => '#1a5276',
                'address' => 'Vin Plaza, Nyeri - Nyahururu Rd, Nyahururu, Kenya',
                'phone' => '0707799114',
                'emergency_phone' => config('pearlie.hospital.emergency_phone'),
                'email' => 'info@pearlhospital.co.ke',
                'website' => 'https://www.pearlhospital.co.ke',
                'whatsapp_number' => config('pearlie.hospital.whatsapp_number'),
                'chatbot_name' => 'Pearlie',
                'site_header_text' => 'Pearl Hospital',
                'site_footer_text' => '© 2026 Pearl Hospital. All rights reserved.',
                'deposit_amount' => 500,
                'slot_duration_minutes' => 30,
                'no_show_grace_minutes' => 30,
                'hours_emergency' => '24/7',
                'hours_outpatient' => '8:00 AM - 6:00 PM, Mon-Sat',
                'default_language' => 'en',
                'supported_languages' => ['en', 'sw'],
                'subscription_plan' => 'enterprise',
                'subscription_status' => 'active',
                'trial_ends_at' => null,
                'is_active' => true,
            ],
        );
        $this->seedServices($pearl, [
            [
                'name' => 'General Consultation',
                'description' => 'Consultation with a general practitioner.',
                'category' => 'General',
                'duration_minutes' => 30,
                'price' => 1500,
                'requires_specialty' => 'General Practitioner',
            ],
            [
                'name' => 'Dental Checkup & Cleaning',
                'description' => 'Dental examination and professional cleaning.',
                'category' => 'Dental',
                'duration_minutes' => 30,
                'price' => 2500,
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
        ]);

        if (app()->isProduction()) {
            return;
        }

        $demo = Hospital::query()->updateOrCreate(
            ['slug' => 'demo'],
            [
                'name' => 'Nairobi Medical Centre',
                'city' => 'Nairobi',
                'county' => 'Nairobi',
                'address' => 'Nairobi, Kenya',
                'phone' => '0700000010',
                'email' => 'info@demohospital.co.ke',
                'chatbot_name' => 'Nairobie',
                'site_header_text' => 'Nairobi Medical Centre',
                'site_footer_text' => '© 2026 Nairobi Medical Centre. All rights reserved.',
                'subscription_plan' => 'professional',
                'subscription_status' => 'active',
                'trial_ends_at' => null,
                'is_active' => true,
            ],
        );

        $this->seedServices($demo, [
            [
                'name' => 'General Consultation',
                'description' => 'Consultation with a general practitioner.',
                'category' => 'General',
                'duration_minutes' => 30,
                'price' => 1200,
                'requires_specialty' => 'General Practitioner',
            ],
            [
                'name' => 'Paediatric Consultation',
                'description' => 'Consultation for infants, children, and adolescents.',
                'category' => 'Paediatrics',
                'duration_minutes' => 30,
                'price' => 1800,
                'requires_specialty' => 'Paediatrician',
            ],
            [
                'name' => 'Dental Checkup',
                'description' => 'Routine dental examination.',
                'category' => 'Dental',
                'duration_minutes' => 30,
                'price' => 2000,
                'requires_specialty' => 'Dentist',
            ],
            [
                'name' => 'Ultrasound Scan',
                'description' => 'Diagnostic ultrasound imaging appointment.',
                'category' => 'Diagnostic',
                'duration_minutes' => 45,
                'price' => 3500,
                'requires_specialty' => 'Radiologist',
            ],
        ]);
    }

    /**
     * @param  array<int, array{name: string, description: string, category: string, duration_minutes: int, price: int, requires_specialty: ?string}>  $services
     */
    private function seedServices(Hospital $hospital, array $services): void
    {
        $serviceNames = array_column($services, 'name');
        $hospital->services()
            ->whereNotIn('name', $serviceNames)
            ->update(['is_active' => false]);

        foreach ($services as $service) {
            $hospital->services()->updateOrCreate(
                ['name' => $service['name']],
                [...$service, 'is_active' => true],
            );
        }
    }
}
