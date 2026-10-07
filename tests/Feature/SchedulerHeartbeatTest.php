<?php

namespace Tests\Feature;

use App\Console\Commands\MpesaHealthCheck;
use App\Console\Commands\ReconcileMpesaPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        Cache::put(
            MpesaHealthCheck::SCHEDULER_HEARTBEAT_CACHE_KEY,
            now()->timestamp,
            600,
        );
        Cache::put(
            ReconcileMpesaPayments::HEARTBEAT_CACHE_KEY,
            now()->timestamp,
            now()->addMinutes(15),
        );

        $this->artisan('mpesa:health-check')
            ->expectsOutputToContain('Scheduler heartbeat')
            ->assertExitCode(0);
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
