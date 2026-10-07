<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
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
    public function handle(): int
    {
        $checks = [];
        $failed = false;
        $warned = false;

        foreach (['slot_holds', 'payment_events'] as $table) {
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
        $reconciliationDescription = $lastReconciliation === null
            ? 'no valid reconciliation command heartbeat found in the last 10 minutes'
            : 'last reconciliation command at '.$lastReconciliation->toDateTimeString();
        $checks[] = [
            'Reconciliation command heartbeat',
            $reconciliationIsRecent ? 'PASS' : 'WARN',
            $reconciliationDescription,
        ];
        $warned = $warned || ! $reconciliationIsRecent;

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
}
