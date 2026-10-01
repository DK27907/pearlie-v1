<?php

return [
    'consumer_key' => env('MPESA_CONSUMER_KEY'),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
    'passkey' => env('MPESA_PASSKEY'),
    'shortcode' => env('MPESA_SHORTCODE', '174379'),
    'callback_url' => env('MPESA_CALLBACK_URL', env('APP_URL').'/api/mpesa/callback'),
    'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),
    'skip_callback_verification_in_local' => env('MPESA_SKIP_VERIFY_LOCAL', true),
    'auto_confirm_on_payment' => env('MPESA_AUTO_CONFIRM', false),
    'safaricom_ip_allowlist' => [
        '196.201.212.0/22',
        '196.201.213.0/24',
        '196.201.214.0/24',
    ],
    'enforce_safaricom_ip_allowlist' => env('MPESA_ENFORCE_IP_ALLOWLIST', true),
    'allowlist_bypass_environments' => ['local', 'testing'],
    'appointment_deposit' => (float) env('PEARLIE_DEPOSIT_AMOUNT', env('MPESA_APPOINTMENT_DEPOSIT', 500)),
    'timeout' => (int) env('MPESA_TIMEOUT', 20),
    'test_phone' => env('MPESA_TEST_PHONE'),
    'endpoints' => [
        'sandbox' => 'https://sandbox.safaricom.co.ke',
        'production' => 'https://api.safaricom.co.ke',
    ],
    'account_reference' => env('MPESA_ACCOUNT_REFERENCE', preg_replace('/[^A-Za-z0-9]/', '', env('APP_NAME', 'MediDeskAI'))),
    'transaction_description' => env('MPESA_TRANSACTION_DESCRIPTION', 'Appointment booking'),
];
