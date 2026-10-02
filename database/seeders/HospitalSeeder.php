<?php

namespace Database\Seeders;

use App\Models\Hospital;
use Illuminate\Database\Seeder;

class HospitalSeeder extends Seeder
{
    public function run(): void
    {
        Hospital::query()->updateOrCreate(
            ['slug' => 'pearl'],
            [
                'name' => config('pearlie.hospital.name', 'Pearl Hospital'),
                'primary_color' => '#0a2f44',
                'secondary_color' => '#1a5276',
                'address' => config('pearlie.hospital.address'),
                'phone' => config('pearlie.hospital.phone'),
                'emergency_phone' => config('pearlie.hospital.emergency_phone'),
                'email' => config('pearlie.hospital.email'),
                'website' => config('pearlie.hospital.website'),
                'whatsapp_number' => config('pearlie.hospital.whatsapp_number'),
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

        if (app()->isProduction()) {
            return;
        }

        Hospital::query()->updateOrCreate(
            ['slug' => 'axiomforge'],
            [
                'name' => 'AxiomForge Digital Solutions',
                'city' => 'Nairobi',
                'county' => 'Nairobi',
                'email' => 'hello@axiomforge.dev',
                'subscription_plan' => 'enterprise',
                'subscription_status' => 'active',
                'is_active' => true,
            ],
        );

        Hospital::query()->updateOrCreate(
            ['slug' => 'demo'],
            [
                'name' => 'Nairobi Medical Centre',
                'city' => 'Nairobi',
                'county' => 'Nairobi',
                'email' => 'info@demohospital.co.ke',
                'subscription_plan' => 'professional',
                'subscription_status' => 'active',
                'trial_ends_at' => null,
                'is_active' => true,
            ],
        );
    }
}
