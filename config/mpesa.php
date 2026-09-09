<?php

return [
    'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),
    'consumer_key' => env('MPESA_CONSUMER_KEY'),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
    'shortcode' => env('MPESA_SHORTCODE'),
    'passkey' => env('MPESA_PASSKEY'),
    'callback_url' => env('MPESA_CALLBACK_URL', env('APP_URL').'/api/mpesa/callback'),
    'account_reference' => env('MPESA_ACCOUNT_REFERENCE', 'PearlHospital'),
    'transaction_description' => env('MPESA_TRANSACTION_DESCRIPTION', 'Pearl Hospital appointment booking'),
    'timeout' => (int) env('MPESA_TIMEOUT', 20),
];
