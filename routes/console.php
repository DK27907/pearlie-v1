<?php

use App\Console\Commands\CleanupSlotHolds;
use App\Console\Commands\MpesaSimulateCallback;
use App\Console\Commands\ReconcileMpesaPayments;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

ArtisanApplication::starting(static function ($artisan): void {
    $artisan->resolveCommands([
        MpesaSimulateCallback::class,
        ReconcileMpesaPayments::class,
        CleanupSlotHolds::class,
    ]);
});

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('doctor:daily-summary')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('appointments:mark-no-shows')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('app:reconcile-mpesa-payments')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
Schedule::command('app:cleanup-slot-holds')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
