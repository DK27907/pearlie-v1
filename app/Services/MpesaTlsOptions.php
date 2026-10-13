<?php

namespace App\Services;

use RuntimeException;

class MpesaTlsOptions
{
    /**
     * @return array{verify?: string}
     */
    public static function options(): array
    {
        $caBundle = config('mpesa.ca_bundle');

        if ($caBundle === null || $caBundle === '') {
            return [];
        }

        if (! is_string($caBundle) || ! is_file($caBundle) || ! is_readable($caBundle)) {
            throw new RuntimeException('The configured M-Pesa CA bundle is missing or unreadable.');
        }

        return ['verify' => $caBundle];
    }
}
