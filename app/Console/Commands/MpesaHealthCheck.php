<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('mpesa:health-check')]
#[Description('Check M-Pesa configuration, reconciliation, and queue health')]
class MpesaHealthCheck extends Command
{
    public const SCHEDULER_HEARTBEAT_CACHE_KEY = 'scheduler:last_run';

    public const SCHEDULER_HEALTH_BASELINE_CACHE_KEY = 'scheduler:health_check_baseline';

    /**
     * @var array<string, int>
     */
    public const SCHEDULED_TASK_HEARTBEATS = [
        'doctor:daily-summary' => 2880,
        'appointments:mark-no-shows' => 60,
        'app:reconcile-mpesa-payments' => 2,
        'app:process-mpesa-callback-inbox --limit=100' => 2,
        'app:cleanup-slot-holds' => 2,
        'app:prune-notification-logs --days=30 --status=failed' => 2880,
        'app:prune-notification-logs --days=90 --status=all' => 2880,
        'app:prune-password-reset-tokens --minutes=60' => 2880,
        'app:prune-conversations --days=90' => 2880,
        'app:prune-payment-events --days=365' => 2880,
    ];

    /**
     * @var array<string, string>
     */
    private const SCHEDULED_TASK_EXPRESSIONS = [
        'doctor:daily-summary' => '0 7 * * *',
        'appointments:mark-no-shows' => '*/30 * * * *',
        'app:reconcile-mpesa-payments' => '* * * * *',
        'app:process-mpesa-callback-inbox --limit=100' => '* * * * *',
        'app:cleanup-slot-holds' => '* * * * *',
        'app:prune-notification-logs --days=30 --status=failed' => '10 2 * * *',
        'app:prune-notification-logs --days=90 --status=all' => '20 2 * * *',
        'app:prune-password-reset-tokens --minutes=60' => '30 2 * * *',
        'app:prune-conversations --days=90' => '40 2 * * *',
        'app:prune-payment-events --days=365' => '50 2 * * *',
    ];

    public static function recordSchedulerHeartbeat(string $command): bool
    {
        $ttlMinutes = self::SCHEDULED_TASK_HEARTBEATS[$command] ?? null;
        if ($ttlMinutes === null) {
            throw new \InvalidArgumentException('Unknown scheduled task: '.$command);
        }

        $timestamp = now()->timestamp;
        Cache::put(self::SCHEDULER_HEARTBEAT_CACHE_KEY, $timestamp, 600);

        return Cache::put(
            self::schedulerHeartbeatCacheKey($command),
            $timestamp,
            $ttlMinutes * 60,
        );
    }

    public static function schedulerHeartbeatCacheKey(string $command): string
    {
        return self::SCHEDULER_HEARTBEAT_CACHE_KEY.':'.$command;
    }

    public function handle(): int
    {
        $checks = [];
        $failed = false;
        $warned = false;

        foreach (['slot_holds', 'payment_events', 'mpesa_callback_inbox'] as $table) {
            $exists = Schema::hasTable($table);
            $checks[] = [$table.' migration', $exists ? 'PASS' : 'FAIL', $exists ? 'table exists' : 'table is missing'];
            $failed = $failed || ! $exists;
        }

        foreach ([
            'consumer_key',
            'consumer_secret',
            'passkey',
            'shortcode',
            'callback_url',
        ] as $key) {
            $value = config('mpesa.'.$key);
            $configured = is_scalar($value) && trim((string) $value) !== '';
            $description = $configured ? 'configured' : 'missing';
            $checks[] = ['Config '.$key, $configured ? 'PASS' : 'FAIL', $description];
            $failed = $failed || ! $configured;
        }

        $lastReconciliationTimestamp = Cache::get(ReconcileMpesaPayments::HEARTBEAT_CACHE_KEY);
        $lastReconciliation = is_int($lastReconciliationTimestamp)
            ? CarbonImmutable::createFromTimestamp($lastReconciliationTimestamp)
            : null;
        $reconciliationIsRecent = $lastReconciliation !== null
            && $lastReconciliation->greaterThanOrEqualTo(now()->subMinutes(10));
        $reconciliationIsDue = $this->hasScheduledWindowPassed('app:reconcile-mpesa-payments');
        $reconciliationStatus = $reconciliationIsRecent
            ? 'PASS'
            : (($lastReconciliation !== null || $reconciliationIsDue) ? 'WARN' : 'NOT-DUE');
        $reconciliationDescription = $lastReconciliation === null
            ? ($reconciliationIsDue
                ? 'scheduled window passed without a reconciliation command heartbeat'
                : 'no reconciliation run is due since health monitoring began')
            : 'last reconciliation command at '.$lastReconciliation->toDateTimeString();
        $checks[] = [
            'Reconciliation command heartbeat',
            $reconciliationStatus,
            $reconciliationDescription,
        ];
        $warned = $warned || (! $reconciliationIsRecent
            && ($lastReconciliation !== null || $reconciliationIsDue));

        $lastSchedulerTimestamp = Cache::get(self::SCHEDULER_HEARTBEAT_CACHE_KEY);
        $schedulerIsRecent = is_int($lastSchedulerTimestamp)
            && $lastSchedulerTimestamp >= now()->subMinutes(5)->timestamp;
        $schedulerIsDue = $this->hasAnyScheduledWindowPassed();
        $schedulerDescription = is_int($lastSchedulerTimestamp)
            ? 'last successful scheduled task at '.CarbonImmutable::createFromTimestamp($lastSchedulerTimestamp)->toDateTimeString()
            : ($schedulerIsDue
                ? 'scheduled task window passed without a successful heartbeat'
                : 'no scheduled task is due since health monitoring began');
        $checks[] = [
            'Scheduler heartbeat',
            $schedulerIsRecent
                ? 'PASS'
                : ((is_int($lastSchedulerTimestamp) || $schedulerIsDue) ? 'FAIL' : 'NOT-DUE'),
            $schedulerDescription,
        ];
        $failed = $failed || (! $schedulerIsRecent
            && (is_int($lastSchedulerTimestamp) || $schedulerIsDue));

        foreach (self::SCHEDULED_TASK_HEARTBEATS as $command => $ttlMinutes) {
            $timestamp = Cache::get(self::schedulerHeartbeatCacheKey($command));
            $isRecent = is_int($timestamp)
                && $timestamp >= now()->subMinutes($ttlMinutes)->timestamp;
            $isDue = $this->hasScheduledWindowPassed($command);
            $description = is_int($timestamp)
                ? 'last successful run at '.CarbonImmutable::createFromTimestamp($timestamp)->toDateTimeString()
                : ($isDue
                    ? 'scheduled window passed without a successful run'
                    : 'no run is due since health monitoring began');
            $checks[] = [
                'Scheduled task '.$command,
                $isRecent ? 'PASS' : ((is_int($timestamp) || $isDue) ? 'STALE' : 'NOT-DUE'),
                $description,
            ];
            $failed = $failed || (! $isRecent && (is_int($timestamp) || $isDue));
        }

        if (Schema::hasTable('failed_jobs')) {
            $recentFailures = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subHour())
                ->count();
            $failureStatus = $recentFailures > 0 ? 'WARN' : 'PASS';
            $failureDescription = $recentFailures.' failed job(s) in the last hour';
        } else {
            $failureStatus = 'WARN';
            $failureDescription = 'failed_jobs table is missing';
        }
        $checks[] = ['Queue worker health', $failureStatus, $failureDescription];
        $warned = $warned || $failureStatus === 'WARN';

        $this->table(['Check', 'Status', 'Details'], $checks);

        if ($failed) {
            return self::FAILURE;
        }

        return $warned ? 2 : self::SUCCESS;
    }

    private function hasScheduledWindowPassed(string $command): bool
    {
        $baseline = Cache::get(self::SCHEDULER_HEALTH_BASELINE_CACHE_KEY);
        if (! is_int($baseline)) {
            $baseline = now()->timestamp;
            Cache::add(
                self::SCHEDULER_HEALTH_BASELINE_CACHE_KEY,
                $baseline,
                now()->addYear(),
            );
            $baseline = Cache::get(self::SCHEDULER_HEALTH_BASELINE_CACHE_KEY);
        }

        $expression = self::SCHEDULED_TASK_EXPRESSIONS[$command];
        $nextRun = CronExpression::factory($expression)
            ->getNextRunDate(CarbonImmutable::createFromTimestamp($baseline));

        return CarbonImmutable::instance($nextRun)->lessThanOrEqualTo(now());
    }

    private function hasAnyScheduledWindowPassed(): bool
    {
        foreach (array_keys(self::SCHEDULED_TASK_EXPRESSIONS) as $command) {
            if ($this->hasScheduledWindowPassed($command)) {
                return true;
            }
        }

        return false;
    }
}
