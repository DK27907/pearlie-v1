<?php

return [
    // Confidence threshold under which messages are escalated to human staff
    'escalation_threshold' => env('PEARLIE_ESCALATION_THRESHOLD', 0.7),

    // Max conversation history messages to include
    'max_history' => env('PEARLIE_MAX_HISTORY', 10),

    // Hospital contact used in fallback messages
    'hospital' => [
        'name' => env('PEARL_HOSPITAL_NAME', 'Pearl Hospital'),
        'phone' => env('PEARL_HOSPITAL_PHONE', '0700000000'),
        'address' => env('PEARL_HOSPITAL_ADDRESS', 'Vin Plaza, Nyahururu-Nyeri Road, Nyahururu'),
        'email' => env('PEARL_HOSPITAL_EMAIL', 'info@pearlhospital.co.ke'),
    ],

    // Notification targets (comma-separated emails)
    'notify_emails' => explode(',', env('PEARLIE_NOTIFY_EMAILS', env('PEARL_HOSPITAL_EMAIL', 'info@pearlhospital.co.ke'))),

    // Optional SMS provider endpoint for sending escalation SMS messages.
    // The provider should accept POST with json: { to: "+2547..", message: "..." }
    'sms_provider_url' => env('PEARLIE_SMS_PROVIDER_URL', null),

    // Single escalation notification email and phone (used by EscalationService)
    'escalation_email' => env('ESCALATION_NOTIFICATION_EMAIL', env('PEARLIE_NOTIFY_EMAILS', 'info@pearlhospital.co.ke')),
    'escalation_phone' => env('ESCALATION_NOTIFICATION_PHONE', env('PEARL_HOSPITAL_PHONE', '0700000000')),

    // Notification retry/backoff settings (tune via .env)
    'notification_retry_attempts' => env('PEARLIE_NOTIFICATION_RETRY_ATTEMPTS', 3),
    'notification_backoff_ms' => env('PEARLIE_NOTIFICATION_BACKOFF_MS', 500),

    // Metrics and error reporting
    // If set, report() will be invoked on persistent failures; integrate Sentry or your error handler to capture.
    'report_failures' => env('PEARLIE_REPORT_NOTIFICATION_FAILURES', true),

    'booking_fee' => (int) env('PEARLIE_BOOKING_FEE', 500),
];
