<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

#[Signature('app:refresh-mpesa-ca-bundle')]
#[Description('Export trusted Windows root certificates for M-Pesa TLS verification')]
class RefreshMpesaCaBundle extends Command
{
    public function handle(): int
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->error('This command only exports the Windows trusted root certificate stores.');

            return self::FAILURE;
        }

        $directory = storage_path('app/mpesa');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error('Unable to create the M-Pesa certificate directory.');

            return self::FAILURE;
        }

        $temporaryPath = tempnam($directory, '.ca-bundle-');
        if ($temporaryPath === false) {
            $this->error('Unable to create a temporary M-Pesa CA bundle.');

            return self::FAILURE;
        }

        $script = str_replace(
            '__OUTPUT_PATH__',
            str_replace("'", "''", $temporaryPath),
            <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$certificates = @(Get-ChildItem -Path Cert:\CurrentUser\Root,Cert:\LocalMachine\Root | Sort-Object -Property Thumbprint -Unique)
$blocks = @($certificates | ForEach-Object { $encoded = [Convert]::ToBase64String($_.RawData); $lines = [System.Text.RegularExpressions.Regex]::Matches($encoded, '.{1,64}') | ForEach-Object { $_.Value }; '-----BEGIN CERTIFICATE-----' + [Environment]::NewLine + ($lines -join [Environment]::NewLine) + [Environment]::NewLine + '-----END CERTIFICATE-----' })
$bundle = $blocks -join [Environment]::NewLine
[System.IO.File]::WriteAllText('__OUTPUT_PATH__', $bundle, (New-Object System.Text.UTF8Encoding($false)))
POWERSHELL
        );

        $process = new Process([
            'powershell.exe',
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            '-',
        ]);
        $process->setTimeout(30);
        $process->setInput($script);
        $process->run();

        if (! $process->isSuccessful()) {
            unlink($temporaryPath);
            $this->error('Unable to export the Windows trusted root certificates: '.trim($process->getErrorOutput()));

            return self::FAILURE;
        }

        $bundle = file_get_contents($temporaryPath);
        $certificateCount = is_string($bundle)
            ? substr_count($bundle, '-----BEGIN CERTIFICATE-----')
            : 0;
        $endMarkerCount = is_string($bundle)
            ? substr_count($bundle, '-----END CERTIFICATE-----')
            : 0;

        if ($certificateCount === 0 || $certificateCount !== $endMarkerCount) {
            unlink($temporaryPath);
            $this->error('Windows returned no usable trusted root certificates.');

            return self::FAILURE;
        }

        $path = $directory.DIRECTORY_SEPARATOR.'ca-bundle.pem';
        if (! rename($temporaryPath, $path)) {
            unlink($temporaryPath);
            $this->error('Unable to write the M-Pesa CA bundle.');

            return self::FAILURE;
        }

        $this->info('Exported '.$certificateCount.' trusted root certificates to '.$path);

        return self::SUCCESS;
    }
}
