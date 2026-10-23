<?php

return [
    'allow_global_fallback' => (bool) env(
        'INTEGRATIONS_ALLOW_GLOBAL_FALLBACK',
        env('APP_ENV') === 'testing',
    ),
    'allow_mpesa_global_fallback' => (bool) env(
        'MPESA_ALLOW_GLOBAL_FALLBACK',
        env('APP_ENV') === 'local',
    ),
];
