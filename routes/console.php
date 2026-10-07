<?php

use App\Console\Commands\CleanupSlotHolds;
use App\Console\Commands\MpesaHealthCheck;
use App\Console\Commands\MpesaSimulateCallback;
use App\Console\Commands\MpesaVerifySandboxCredentials;
use App\Console\Commands\ReconcileMpesaPayments;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

ArtisanApplication::starting(static function ($artisan): void {
    $artisan->resolveCommands([
        MpesaSimulateCallback::class,
        ReconcileMpesaPayments::class,
        CleanupSlotHolds::class,
        MpesaHealthCheck::class,
        MpesaVerifySandboxCredentials::class,
    ]);
});

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$recordSchedulerHeartbeat = static fn (): bool => Cache::put(
    MpesaHealthCheck::SCHEDULER_HEARTBEAT_CACHE_KEY,
    now()->timestamp,
    600,
);

Schedule::command('doctor:daily-summary')
    ->dailyAt('07:00')
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess($recordSchedulerHeartbeat);
Schedule::command('appointments:mark-no-shows')
    ->everyThirtyMinutes()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess($recordSchedulerHeartbeat);
Schedule::command('app:reconcile-mpesa-payments')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess($recordSchedulerHeartbeat);
Schedule::command('app:cleanup-slot-holds')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess($recordSchedulerHeartbeat);
