<?php

namespace App\Http\Middleware;

use App\Models\Hospital;
use App\Services\HospitalMailService;
use App\Services\HospitalSettings;
use Closure;
use Illuminate\Mail\Mailable;

class ApplyHospitalMailSettings
{
    public function __construct(private readonly int $hospitalId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $hospital = Hospital::withoutGlobalScopes()->findOrFail($this->hospitalId);
        $credentials = (new HospitalSettings)->for($hospital)->credential('email');
        if (isset($job->mailable) && $job->mailable instanceof Mailable && $credentials) {
            if (filled($credentials['mailer'] ?? null)) {
                $job->mailable->mailer($credentials['mailer']);
            }
            if (filled($credentials['from_address'] ?? null)) {
                $job->mailable->from(
                    $credentials['from_address'],
                    $credentials['from_name'] ?? config('mail.from.name'),
                );
            }
        }

        return app(HospitalMailService::class)->runForHospital(
            $this->hospitalId,
            fn (): mixed => $next($job),
        );
    }
}
