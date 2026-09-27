<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'users',
        'knowledge_bases',
        'conversations',
        'escalations',
        'appointment_requests',
        'doctor_availabilities',
        'doctor_unavailable_dates',
        'mpesa_payments',
        'doctor_invites',
        'invites',
    ];

    public function up(): void
    {
        $now = now();
        DB::table('hospitals')->insertOrIgnore([
            'name' => 'Pearl Hospital',
            'slug' => 'pearl',
            'primary_color' => '#0a2f44',
            'secondary_color' => '#1a5276',
            'address' => 'Vin Plaza, Nyahururu-Nyeri Road, Nyahururu, Kenya',
            'city' => 'Nyahururu',
            'county' => 'Laikipia',
            'phone' => '0707799114',
            'emergency_phone' => '0707799114',
            'email' => 'info@pearlhospital.co.ke',
            'website' => 'https://www.pearlhospital.co.ke',
            'whatsapp_number' => '254707799114',
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'no_show_grace_minutes' => 30,
            'hours_emergency' => '24/7',
            'hours_outpatient' => '8:00 AM - 6:00 PM, Mon-Sat',
            'default_language' => 'en',
            'supported_languages' => json_encode(['en', 'sw']),
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $pearlHospitalId = (int) DB::table('hospitals')->where('slug', 'pearl')->value('id');

        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->foreignId('hospital_id')
                    ->nullable()
                    ->constrained('hospitals')
                    ->cascadeOnDelete();
                $table->index('hospital_id', $tableName.'_hospital_id_index');
            });

            DB::table($tableName)->whereNull('hospital_id')->update(['hospital_id' => $pearlHospitalId]);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex($tableName.'_hospital_id_index');
                $table->dropConstrainedForeignId('hospital_id');
            });
        }
    }
};
