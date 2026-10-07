<?php

namespace App\Services;

use App\Http\Middleware\ApplyHospitalMailSettings;
use App\Models\Hospital;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

class HospitalMailService
{
    public function send(string $recipient, Mailable $mailable): mixed
    {
        $hospital = hospital();
        $credentials = HospitalSettings::currentOrNull()?->credential('email');
        $this->prepareMailable($mailable, $credentials);
        if ($hospital && $mailable instanceof ShouldQueue) {
            $this->attachHospitalMiddleware($mailable, $hospital);
        }

        return $this->withMailConfig(
            fn (): mixed => Mail::to($recipient)->send($mailable),
            $credentials,
        );
    }

    public function queue(string $recipient, Mailable $mailable): mixed
    {
        $hospital = hospital();
        $credentials = HospitalSettings::currentOrNull()?->credential('email');
        if ($hospital) {
            $this->attachHospitalMiddleware($mailable, $hospital);
        }

        return $this->withMailConfig(
            fn (): mixed => Mail::to($recipient)->queue($mailable),
            $credentials,
        );
    }

    /**
     * @param  callable(): mixed  $callback
     */
    public function runForHospital(int $hospitalId, callable $callback): mixed
    {
        $hospital = Hospital::withoutGlobalScopes()->findOrFail($hospitalId);
        $hadCurrentHospital = app()->bound('currentHospital');
        $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;
        app()->instance('currentHospital', $hospital);

        try {
            $credentials = HospitalSettings::current()->credential('email');

            return $this->withMailConfig($callback, $credentials);
        } finally {
            if ($hadCurrentHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $credentials
     */
    private function prepareMailable(Mailable $mailable, ?array $credentials): void
    {
        $fromAddress = $credentials['from_address'] ?? config('mail.from.address');
        $fromName = $credentials['from_name'] ?? config('mail.from.name');
        if (filled($fromAddress)) {
            $mailable->from($fromAddress, $fromName);
        }
        if (filled($credentials['mailer'] ?? null)) {
            $mailable->mailer($credentials['mailer']);
        }
    }

    private function attachHospitalMiddleware(Mailable $mailable, Hospital $hospital): void
    {
        $mailable->through([
            ...$mailable->middleware,
            new ApplyHospitalMailSettings((int) $hospital->id),
        ]);
    }

    /**
     * @param  callable(): mixed  $callback
     * @param  array<string, mixed>|null  $credentials
     */
    private function withMailConfig(callable $callback, ?array $credentials): mixed
    {
        $configKeys = [
            'mail.default',
            'mail.from.address',
            'mail.from.name',
            'mail.mailers.smtp.host',
            'mail.mailers.smtp.port',
            'mail.mailers.smtp.username',
            'mail.mailers.smtp.password',
            'mail.mailers.smtp.scheme',
            'services.resend.key',
        ];
        $originalConfig = [];
        foreach ($configKeys as $key) {
            $originalConfig[$key] = config($key);
        }

        if ($credentials) {
            $mailer = $credentials['mailer'] ?? 'log';
            if (! in_array($mailer, ['resend', 'smtp', 'log'], true)) {
                throw new \RuntimeException("The configured email mailer [{$mailer}] is not supported.");
            }

            config([
                'mail.default' => $mailer,
                'mail.from.address' => $credentials['from_address'] ?? config('mail.from.address'),
                'mail.from.name' => $credentials['from_name'] ?? config('mail.from.name'),
                'mail.mailers.smtp.host' => $credentials['smtp_host'] ?? config('mail.mailers.smtp.host'),
                'mail.mailers.smtp.port' => $credentials['smtp_port'] ?? config('mail.mailers.smtp.port'),
                'mail.mailers.smtp.username' => $credentials['smtp_username'] ?? config('mail.mailers.smtp.username'),
                'mail.mailers.smtp.password' => $credentials['smtp_password'] ?? config('mail.mailers.smtp.password'),
                'mail.mailers.smtp.scheme' => ($credentials['smtp_encryption'] ?? null) === 'none'
                    ? null
                    : ($credentials['smtp_encryption'] ?? config('mail.mailers.smtp.scheme')),
                'services.resend.key' => $credentials['resend_api_key'] ?? config('services.resend.key'),
            ]);
        }

        Mail::forgetMailers();

        try {
            return $callback();
        } finally {
            config($originalConfig);
            Mail::forgetMailers();
        }
    }
}
