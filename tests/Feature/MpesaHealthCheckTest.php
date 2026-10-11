<?php

namespace Tests\Feature;

use App\Console\Commands\MpesaHealthCheck;
use App\Console\Commands\ReconcileMpesaPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MpesaHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_configuration_and_recent_reconciliation_pass(): void
    {
        $this->configureMpesa();
        $this->recordHeartbeat(now()->subMinutes(2));

        $this->artisan('mpesa:health-check')
            ->expectsOutputToContain('PASS')
            ->assertExitCode(0);
    }

    public function test_health_check_never_displays_configured_credential_values(): void
    {
        $this->configureMpesa();
        $this->recordHeartbeat(now()->subMinutes(2));

        $exitCode = Artisan::call('mpesa:health-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('configured', $output);
        $this->assertStringNotContainsString('sandbox-consumer-key', $output);
        $this->assertStringNotContainsString('san***', $output);
        $this->assertStringNotContainsString('174379', $output);
        $this->assertStringNotContainsString('example.test', $output);
    }

    public function test_missing_consumer_key_fails_the_health_check(): void
    {
        $this->configureMpesa();
        config(['mpesa.consumer_key' => '']);
        $this->recordHeartbeat(now()->subMinutes(2));

        $exitCode = Artisan::call('mpesa:health-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Config consumer_key', $output);
        // Credential values are redacted, so missing is reported in the Details column.
        $this->assertStringContainsString('missing', $output);
    }

    public function test_stale_reconciliation_heartbeat_warns_without_failing(): void
    {
        $this->configureMpesa();
        $this->recordHeartbeat(now()->subMinutes(11));
        Cache::put(
            MpesaHealthCheck::SCHEDULER_HEARTBEAT_CACHE_KEY,
            now()->timestamp,
            600,
        );

        $this->artisan('mpesa:health-check')
            ->assertExitCode(2);
    }

    public function test_reconciliation_command_records_heartbeat_when_no_payments_are_stale(): void
    {
        $this->artisan('app:reconcile-mpesa-payments')
            ->expectsOutput('Reconciled 0 M-Pesa payment(s).')
            ->assertExitCode(0);

        $this->assertIsInt(Cache::get(ReconcileMpesaPayments::HEARTBEAT_CACHE_KEY));
    }

    public function test_recent_failed_jobs_warn_without_failing(): void
    {
        $this->configureMpesa();
        $this->recordHeartbeat(now()->subMinutes(2));
        DB::table('failed_jobs')->insert([
            'uuid' => 'health-check-failed-job',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Test failure',
            'failed_at' => now()->subMinutes(20),
        ]);

        $this->artisan('mpesa:health-check')
            ->expectsOutputToContain('1 failed job(s) in the last hour')
            ->assertExitCode(2);
    }

    private function configureMpesa(): void
    {
        config([
            'mpesa.consumer_key' => 'sandbox-consumer-key',
            'mpesa.consumer_secret' => 'sandbox-consumer-secret',
            'mpesa.passkey' => 'sandbox-passkey',
            'mpesa.shortcode' => '174379',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
        ]);
    }

    private function recordHeartbeat(Carbon $ranAt): void
    {
        Cache::put(
            ReconcileMpesaPayments::HEARTBEAT_CACHE_KEY,
            $ranAt->timestamp,
            now()->addMinutes(15),
        );
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
}
