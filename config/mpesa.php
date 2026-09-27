<?php

return [
    'consumer_key' => env('MPESA_CONSUMER_KEY'),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
    'passkey' => env('MPESA_PASSKEY'),
    'shortcode' => env('MPESA_SHORTCODE', '174379'),
    'callback_url' => env('MPESA_CALLBACK_URL', env('APP_URL').'/api/mpesa/callback'),
    'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),
    'appointment_deposit' => (float) env('PEARLIE_DEPOSIT_AMOUNT', env('MPESA_APPOINTMENT_DEPOSIT', 500)),
    'timeout' => (int) env('MPESA_TIMEOUT', 20),
    'endpoints' => [
        'sandbox' => 'https://sandbox.safaricom.co.ke',
        'production' => 'https://api.safaricom.co.ke',
    ],
    'account_reference' => env('MPESA_ACCOUNT_REFERENCE', preg_replace('/[^A-Za-z0-9]/', '', env('PEARLIE_HOSPITAL_NAME', 'Pearl Hospital'))),
    'transaction_description' => env('MPESA_TRANSACTION_DESCRIPTION', env('PEARLIE_HOSPITAL_NAME', 'Pearl Hospital').' appointment booking'),
];
