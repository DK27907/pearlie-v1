<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('doctor:daily-summary')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('appointments:mark-no-shows')->everyThirtyMinutes()->withoutOverlapping();
