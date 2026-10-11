<?php

namespace Tests\Feature;

use App\Console\Commands\MpesaHealthCheck;
use App\Console\Commands\ReconcileMpesaPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchedulerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_scheduler_heartbeat_fails_the_mpesa_health_check(): void
    {
        $this->configureMpesaHealthChecks();
        Cache::put(
            MpesaHealthCheck::SCHEDULER_HEARTBEAT_CACHE_KEY,
            now()->subMinutes(6)->timestamp,
            600,
        );

        $this->artisan('mpesa:health-check')
            ->expectsOutputToContain('Scheduler heartbeat')
            ->assertExitCode(1);
    }

    public function test_fresh_scheduler_heartbeat_passes_the_mpesa_health_check(): void
    {
        $this->configureMpesaHealthChecks();
        $this->recordFreshSchedulerHeartbeats();
        Cache::put(
            ReconcileMpesaPayments::HEARTBEAT_CACHE_KEY,
            now()->timestamp,
            now()->addMinutes(15),
        );

        $this->artisan('mpesa:health-check')
            ->expectsOutputToContain('Scheduler heartbeat')
            ->assertExitCode(0);
    }

    public function test_health_check_names_a_stale_scheduled_task(): void
    {
        $this->configureMpesaHealthChecks();
        $this->recordFreshSchedulerHeartbeats();
        Cache::put(
            ReconcileMpesaPayments::HEARTBEAT_CACHE_KEY,
            now()->timestamp,
            now()->addMinutes(15),
        );
        Cache::put(
            MpesaHealthCheck::schedulerHeartbeatCacheKey('app:reconcile-mpesa-payments'),
            now()->subMinutes(3)->timestamp,
            600,
        );

        $exitCode = Artisan::call('mpesa:health-check');

        $this->assertSame(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Scheduled task app:reconcile-mpesa-payments', $output);
        $this->assertStringContainsString('STALE', $output);
    }

    public function test_fresh_scheduler_history_reports_not_due_tasks_without_failing(): void
    {
        $this->configureMpesaHealthChecks();
        Cache::forget(MpesaHealthCheck::SCHEDULER_HEARTBEAT_CACHE_KEY);
        Cache::forget(MpesaHealthCheck::SCHEDULER_HEALTH_BASELINE_CACHE_KEY);
        Cache::forget(ReconcileMpesaPayments::HEARTBEAT_CACHE_KEY);
        foreach (MpesaHealthCheck::SCHEDULED_TASK_HEARTBEATS as $command => $ttlMinutes) {
            Cache::forget(MpesaHealthCheck::schedulerHeartbeatCacheKey($command));
        }

        $exitCode = Artisan::call('mpesa:health-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Scheduled task doctor:daily-summary', $output);
        $this->assertStringContainsString('NOT-DUE', $output);
        $this->assertStringNotContainsString('STALE', $output);
    }

    public function test_missing_heartbeat_becomes_stale_after_its_scheduled_window(): void
    {
        $this->configureMpesaHealthChecks();
        Cache::forget(MpesaHealthCheck::SCHEDULER_HEARTBEAT_CACHE_KEY);
        Cache::forget(MpesaHealthCheck::SCHEDULER_HEALTH_BASELINE_CACHE_KEY);
        Cache::forget(ReconcileMpesaPayments::HEARTBEAT_CACHE_KEY);
        foreach (MpesaHealthCheck::SCHEDULED_TASK_HEARTBEATS as $command => $ttlMinutes) {
            Cache::forget(MpesaHealthCheck::schedulerHeartbeatCacheKey($command));
        }

        $this->assertSame(0, Artisan::call('mpesa:health-check'));
        $this->travel(2)->minutes();

        $exitCode = Artisan::call('mpesa:health-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Scheduled task app:reconcile-mpesa-payments', $output);
        $this->assertStringContainsString('STALE', $output);
    }

    private function recordFreshSchedulerHeartbeats(): void
    {
        Cache::put(
            MpesaHealthCheck::SCHEDULER_HEARTBEAT_CACHE_KEY,
            now()->timestamp,
            600,
        );

        foreach (MpesaHealthCheck::SCHEDULED_TASK_HEARTBEATS as $command => $ttlMinutes) {
            Cache::put(
                MpesaHealthCheck::schedulerHeartbeatCacheKey($command),
                now()->timestamp,
                $ttlMinutes * 60,
            );
        }
    }

    private function configureMpesaHealthChecks(): void
    {
        config([
            'mpesa.consumer_key' => 'test-consumer-key',
            'mpesa.consumer_secret' => 'test-consumer-secret',
            'mpesa.passkey' => 'test-passkey',
            'mpesa.shortcode' => '174379',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
        ]);
    }
}
