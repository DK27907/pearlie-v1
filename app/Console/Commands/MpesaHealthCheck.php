<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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
            $description = $configured
                ? $key.'='.substr((string) $value, 0, 3).'***'
                : $key.' is missing';
            $checks[] = ['Config '.$key, $configured ? 'PASS' : 'FAIL', $description];
            $failed = $failed || ! $configured;
        }

        $reconciliationExists = Schema::hasTable('payment_events');
        $lastReconciliation = $reconciliationExists
            ? DB::table('payment_events')
                ->where('event', 'reconciliation_result')
                ->latest('created_at')
                ->value('created_at')
            : null;
        $reconciliationIsRecent = $lastReconciliation !== null
            && $lastReconciliation >= now()->subMinutes(10)->toDateTimeString();
        $reconciliationDescription = $lastReconciliation === null
            ? 'no reconciliation event found in the last 10 minutes'
            : 'last reconciliation event at '.$lastReconciliation;
        $checks[] = [
            'Scheduler heartbeat',
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
