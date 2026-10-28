<?php

$hospitalEmail = env('PEARLIE_HOSPITAL_EMAIL', env('PEARL_HOSPITAL_EMAIL'));

return [
    'default_hospital_slug' => env('PEARLIE_DEFAULT_HOSPITAL', 'pearl'),
    'marketing' => [
        'name' => env('MEDIDESK_NAME', 'MediDesk Assistant'),
        'tagline' => env('MEDIDESK_TAGLINE', 'The smarter way to care for your community'),
        'sales_email' => env('MEDIDESK_SALES_EMAIL', 'sales@axiomforge.co.ke'),
        'sales_whatsapp' => env('MEDIDESK_SALES_WHATSAPP'),
    ],
    'escalation_threshold' => (float) env('PEARLIE_ESCALATION_THRESHOLD', 0.7),
    'max_history' => (int) env('PEARLIE_MAX_HISTORY', 10),

    'hospital' => [
        'name' => env('PEARLIE_HOSPITAL_NAME', env('PEARL_HOSPITAL_NAME', 'MediDesk Assistant')),
        'location' => env('PEARLIE_HOSPITAL_LOCATION', env('PEARL_HOSPITAL_ADDRESS', '')),
        'email' => $hospitalEmail,
        'website' => env('PEARLIE_HOSPITAL_WEBSITE', ''),
        'emergency_phone' => env('PEARLIE_EMERGENCY_PHONE', env('PEARL_HOSPITAL_PHONE', '')),
        'appointment_phone' => env('PEARLIE_APPOINTMENT_PHONE', env('PEARL_HOSPITAL_PHONE', '')),
        'whatsapp_number' => env('PEARLIE_WHATSAPP_NUMBER', ''),
        'hours_emergency' => env('PEARLIE_HOURS_EMERGENCY', '24/7'),
        'hours_outpatient' => env('PEARLIE_HOURS_OUTPATIENT', '8:00 AM - 6:00 PM, Mon-Sat'),

        // Backward-compatible aliases for existing integrations.
        'phone' => env('PEARLIE_APPOINTMENT_PHONE', env('PEARL_HOSPITAL_PHONE', '')),
        'address' => env('PEARLIE_HOSPITAL_LOCATION', env('PEARL_HOSPITAL_ADDRESS', '')),
    ],

    'appointment' => [
        'deposit_amount' => (float) env('PEARLIE_DEPOSIT_AMOUNT', env('PEARLIE_BOOKING_FEE', 500)),
        'slot_duration_minutes' => (int) env('PEARLIE_SLOT_DURATION', 30),
        'default_schedule' => [
            'days' => [1, 2, 3, 4, 5],
            'start_time' => '09:00',
            'end_time' => '17:00',
        ],
    ],

    'doctors' => [
        'seed_accounts' => [
            [
                'name' => env('PEARLIE_DOCTOR_ONE_NAME', 'Dr. John Kamau'),
                'email' => env('PEARLIE_DOCTOR_ONE_EMAIL', 'doctor1@pearlhospital.co.ke'),
                'specialization' => env('PEARLIE_DOCTOR_ONE_SPECIALIZATION', 'General Medicine'),
                'phone' => env('PEARLIE_DOCTOR_ONE_PHONE', '0700000001'),
            ],
            [
                'name' => env('PEARLIE_DOCTOR_TWO_NAME', 'Dr. Mary Wanjiru'),
                'email' => env('PEARLIE_DOCTOR_TWO_EMAIL', 'doctor2@pearlhospital.co.ke'),
                'specialization' => env('PEARLIE_DOCTOR_TWO_SPECIALIZATION', 'Pediatrics'),
                'phone' => env('PEARLIE_DOCTOR_TWO_PHONE', '0700000002'),
            ],
        ],
    ],

    'ai' => [
        'default_language' => env('PEARLIE_DEFAULT_LANGUAGE', 'en'),
        'supported_languages' => ['en', 'sw'],
    ],

    'no_show' => [
        'grace_minutes' => (int) env('PEARLIE_NO_SHOW_GRACE_MINUTES', 30),
    ],

    'escalation' => [
        'notify_phone' => env('PEARLIE_ESCALATION_PHONE', env('ESCALATION_NOTIFICATION_PHONE', '')),
        'notify_whatsapp' => env('PEARLIE_ESCALATION_WHATSAPP', ''),
        'max_wait_minutes' => (int) env('PEARLIE_ESCALATION_MAX_WAIT', 30),
        'auto_escalate_keywords' => [
            'human',
            'person',
            'nurse',
            'doctor',
            'nataka mtu',
            'msaidie',
            'help me',
            'emergency',
            'dharura',
            'haraka',
        ],
    ],

    'notify_emails' => explode(',', env('PEARLIE_NOTIFY_EMAILS', $hospitalEmail)),
    'sms_provider_url' => env('PEARLIE_SMS_PROVIDER_URL'),
    'escalation_email' => env('ESCALATION_NOTIFICATION_EMAIL', env('PEARLIE_NOTIFY_EMAILS', $hospitalEmail)),
    'escalation_phone' => env('ESCALATION_NOTIFICATION_PHONE', env('PEARLIE_EMERGENCY_PHONE', '')),
    'notification_retry_attempts' => (int) env('PEARLIE_NOTIFICATION_RETRY_ATTEMPTS', 3),
    'notification_backoff_ms' => (int) env('PEARLIE_NOTIFICATION_BACKOFF_MS', 500),
    'report_failures' => (bool) env('PEARLIE_REPORT_NOTIFICATION_FAILURES', true),

    // Backward-compatible alias for callers that have not yet moved to appointment.deposit_amount.
    'booking_fee' => (float) env('PEARLIE_DEPOSIT_AMOUNT', env('PEARLIE_BOOKING_FEE', 500)),
];
