<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CURRENT_DEFAULT = 'Hi {patient_name}, this is a reminder of your appointment with {doctor_name} on {appointment_time}. Reply 1 to confirm, 2 to reschedule.';

    private const CURRENT_DEFAULT_WITH_SERVICE = 'Hi {patient_name}, this is a reminder of your {service_name} appointment with {doctor_name} on {appointment_time}. Reply 1 to confirm, 2 to reschedule.';

    private const LEGACY_DEFAULT = 'Hello {patient_name}, this is a reminder of your appointment with {doctor_name} tomorrow at {appointment_time}. Reply STOP to opt out.';

    private const LEGACY_DEFAULT_WITH_SERVICE = 'Hello {patient_name}, this is a reminder of your {service_name} appointment with {doctor_name} tomorrow at {appointment_time}. Reply STOP to opt out.';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('hospitals')
            ->where('reminder_message_template', self::CURRENT_DEFAULT)
            ->update(['reminder_message_template' => self::CURRENT_DEFAULT_WITH_SERVICE]);

        DB::table('hospitals')
            ->where('reminder_message_template', self::LEGACY_DEFAULT)
            ->update(['reminder_message_template' => self::LEGACY_DEFAULT_WITH_SERVICE]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('hospitals')
            ->where('reminder_message_template', self::CURRENT_DEFAULT_WITH_SERVICE)
            ->update(['reminder_message_template' => self::CURRENT_DEFAULT]);

        DB::table('hospitals')
            ->where('reminder_message_template', self::LEGACY_DEFAULT_WITH_SERVICE)
            ->update(['reminder_message_template' => self::LEGACY_DEFAULT]);
    }
};
