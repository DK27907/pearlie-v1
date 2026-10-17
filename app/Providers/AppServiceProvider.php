<?php

namespace App\Providers;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);

        $tenantRateLimitKey = static function (Request $request): string {
            $tenant = (string) ($request->route('slug') ?? 'pearl');
            $sessionKey = 'tenant_rate_limit_session_'.hash('sha256', $tenant);
            $sessionId = $request->session()->get($sessionKey);

            if (! is_string($sessionId) || $sessionId === '') {
                $sessionId = (string) Str::uuid();
                $request->session()->put($sessionKey, $sessionId);
            }

            return hash('sha256', implode('|', [$sessionId, (string) $request->ip(), $tenant]));
        };
        RateLimiter::for('tenant-chat', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by($tenantRateLimitKey($request)));
        RateLimiter::for('tenant-chat-poll', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by($tenantRateLimitKey($request)));
        RateLimiter::for('tenant-chat-reset', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by($tenantRateLimitKey($request)));

        $integrationCredentials = [
            'GROQ_API_KEY' => config('services.groq.api_key'),
            'MPESA_CONSUMER_KEY' => config('mpesa.consumer_key'),
            'MPESA_CONSUMER_SECRET' => config('mpesa.consumer_secret'),
            'MPESA_PASSKEY' => config('mpesa.passkey'),
            'WHATSAPP_ACCESS_TOKEN' => config('whatsapp.access_token'),
            'WHATSAPP_PHONE_NUMBER_ID' => config('whatsapp.phone_number_id'),
            'WHATSAPP_APP_SECRET' => config('whatsapp.app_secret'),
        ];

        foreach ($integrationCredentials as $key => $value) {
            if (blank($value)) {
                Log::warning('Critical integration credential is missing.', ['key' => $key]);
            }
        }

        $hasAfricaTalking = filled(config('services.africastalking.username'))
            && filled(config('services.africastalking.api_key'));
        $hasTwilio = filled(config('services.twilio.from_number'))
            && (
                (filled(config('services.twilio.account_sid')) && filled(config('services.twilio.auth_token')))
                || (filled(config('services.twilio.api_key')) && filled(config('services.twilio.api_secret')))
            );

        if (! $hasAfricaTalking && ! $hasTwilio) {
            foreach ([
                'AFRICASTALKING_USERNAME' => config('services.africastalking.username'),
                'AFRICASTALKING_API_KEY' => config('services.africastalking.api_key'),
                'TWILIO_ACCOUNT_SID' => config('services.twilio.account_sid'),
                'TWILIO_TOKEN' => config('services.twilio.auth_token'),
                'TWILIO_API_KEY' => config('services.twilio.api_key'),
                'TWILIO_API_SECRET' => config('services.twilio.api_secret'),
                'TWILIO_FROM_NUMBER' => config('services.twilio.from_number'),
            ] as $key => $value) {
                if (blank($value)) {
                    Log::warning('SMS provider credential is missing.', ['key' => $key]);
                }
            }
        }

        $this->logMissingMailCredentials();

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return URL::route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
                'hospital' => $user->hospital?->slug,
            ]);
        });

        // Register is_admin middleware alias so routes can use it
        $router = $this->app['router'] ?? null;
        if ($router) {
            $router->aliasMiddleware('is_admin', EnsureUserIsAdmin::class);

        }
    }

    private function logMissingMailCredentials(): void
    {
        $mailer = (string) config('mail.default');
        $requiredCredentials = match ($mailer) {
            'resend' => ['RESEND_API_KEY' => config('services.resend.key')],
            'mailgun' => [
                'MAILGUN_DOMAIN' => config('services.mailgun.domain'),
                'MAILGUN_SECRET' => config('services.mailgun.secret'),
            ],
            'postmark' => ['POSTMARK_API_KEY' => config('services.postmark.key')],
            'ses' => [
                'AWS_ACCESS_KEY_ID' => config('services.ses.key'),
                'AWS_SECRET_ACCESS_KEY' => config('services.ses.secret'),
            ],
            'smtp' => [
                'MAIL_HOST' => config('mail.mailers.smtp.host'),
                'MAIL_USERNAME' => config('mail.mailers.smtp.username'),
                'MAIL_PASSWORD' => config('mail.mailers.smtp.password'),
            ],
            'log', 'array' => [],
            default => ['MAIL_MAILER' => null],
        };

        foreach ($requiredCredentials as $key => $value) {
            if (blank($value)) {
                Log::warning('Mail provider credential is missing.', [
                    'key' => $key,
                    'mailer' => $mailer,
                ]);
            }
        }

        if (in_array($mailer, ['log', 'array'], true)) {
            Log::warning('Mail is configured without external delivery.', ['mailer' => $mailer]);
        }
    }
}
