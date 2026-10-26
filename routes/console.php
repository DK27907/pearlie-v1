<?php

use App\Console\Commands\CleanupSlotHolds;
use App\Console\Commands\MpesaHealthCheck;
use App\Console\Commands\MpesaSimulateCallback;
use App\Console\Commands\MpesaVerifySandboxCredentials;
use App\Console\Commands\ProcessMpesaCallbackInbox;
use App\Console\Commands\PruneConversations;
use App\Console\Commands\PruneNotificationLogs;
use App\Console\Commands\PrunePasswordResetTokens;
use App\Console\Commands\PrunePaymentEvents;
use App\Console\Commands\ReconcileMpesaPayments;
use App\Console\Commands\RefreshMpesaCaBundle;
use App\Console\Commands\SendAppointmentReminders;
use App\Console\Commands\SendScheduledAnnouncements;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

ArtisanApplication::starting(static function ($artisan): void {
    $artisan->resolveCommands([
        MpesaSimulateCallback::class,
        ProcessMpesaCallbackInbox::class,
        ReconcileMpesaPayments::class,
        CleanupSlotHolds::class,
        MpesaHealthCheck::class,
        RefreshMpesaCaBundle::class,
        MpesaVerifySandboxCredentials::class,
        PruneNotificationLogs::class,
        PrunePasswordResetTokens::class,
        PruneConversations::class,
        PrunePaymentEvents::class,
        SendAppointmentReminders::class,
        SendScheduledAnnouncements::class,
    ]);
});

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('doctor:daily-summary')
    ->dailyAt('07:00')
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess(static fn (): bool => MpesaHealthCheck::recordSchedulerHeartbeat('doctor:daily-summary'));
Schedule::command('appointments:mark-no-shows')
    ->everyThirtyMinutes()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess(static fn (): bool => MpesaHealthCheck::recordSchedulerHeartbeat('appointments:mark-no-shows'));
Schedule::command('app:reconcile-mpesa-payments')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess(static fn (): bool => MpesaHealthCheck::recordSchedulerHeartbeat('app:reconcile-mpesa-payments'));
Schedule::command('app:process-mpesa-callback-inbox --limit=100')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess(static fn (): bool => MpesaHealthCheck::recordSchedulerHeartbeat('app:process-mpesa-callback-inbox --limit=100'));
Schedule::command('app:cleanup-slot-holds')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess(static fn (): bool => MpesaHealthCheck::recordSchedulerHeartbeat('app:cleanup-slot-holds'));
Schedule::command('app:prune-notification-logs --days=30 --status=failed')
    ->dailyAt('02:10')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess(static fn (): bool => MpesaHealthCheck::recordSchedulerHeartbeat('app:prune-notification-logs --days=30 --status=failed'));
Schedule::command('app:prune-notification-logs --days=90 --status=all')
    ->dailyAt('02:20')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess(static fn (): bool => MpesaHealthCheck::recordSchedulerHeartbeat('app:prune-notification-logs --days=90 --status=all'));
Schedule::command('app:prune-password-reset-tokens --minutes=60')
    ->dailyAt('02:30')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess(static fn (): bool => MpesaHealthCheck::recordSchedulerHeartbeat('app:prune-password-reset-tokens --minutes=60'));
Schedule::command('app:prune-conversations --days=90')
    ->dailyAt('02:40')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess(static fn (): bool => MpesaHealthCheck::recordSchedulerHeartbeat('app:prune-conversations --days=90'));
Schedule::command('app:prune-payment-events --days=365')
    ->dailyAt('02:50')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->onSuccess(static fn (): bool => MpesaHealthCheck::recordSchedulerHeartbeat('app:prune-payment-events --days=365'));
Schedule::command('appointments:send-reminders')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
Schedule::command('app:send-scheduled-announcements')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
