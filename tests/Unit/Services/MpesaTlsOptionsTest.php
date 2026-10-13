<?php

namespace Tests\Unit\Services;

use App\Services\MpesaTlsOptions;
use RuntimeException;
use Tests\TestCase;

class MpesaTlsOptionsTest extends TestCase
{
    public function test_uses_a_readable_configured_ca_bundle(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mpesa-ca-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'public certificate bundle');
        config(['mpesa.ca_bundle' => $path]);

        try {
            $this->assertSame(['verify' => $path], MpesaTlsOptions::options());
        } finally {
            unlink($path);
        }
    }

    public function test_keeps_the_http_clients_default_tls_verification_when_no_bundle_is_configured(): void
    {
        config(['mpesa.ca_bundle' => null]);

        $this->assertSame([], MpesaTlsOptions::options());
    }

    public function test_rejects_a_missing_ca_bundle_instead_of_disabling_tls_verification(): void
    {
        config(['mpesa.ca_bundle' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-mpesa-ca-bundle.pem']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The configured M-Pesa CA bundle is missing or unreadable.');

        MpesaTlsOptions::options();
    }
}
