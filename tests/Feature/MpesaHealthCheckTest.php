<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\MpesaPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MpesaHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_configuration_and_recent_reconciliation_pass(): void
    {
        $this->configureMpesa();
        $this->recordReconciliation(now()->subMinutes(2));

        $this->artisan('mpesa:health-check')
            ->expectsOutputToContain('PASS')
            ->assertExitCode(0);
    }

    public function test_missing_consumer_key_fails_the_health_check(): void
    {
        $this->configureMpesa();
        config(['mpesa.consumer_key' => '']);
        $this->recordReconciliation(now()->subMinutes(2));

        $this->artisan('mpesa:health-check')
            ->expectsOutputToContain('consumer_key is missing')
            ->assertExitCode(1);
    }

    public function test_stale_reconciliation_warns_without_failing(): void
    {
        $this->configureMpesa();
        $this->recordReconciliation(now()->subMinutes(11));

        $this->artisan('mpesa:health-check')
            ->assertExitCode(2);
    }

    public function test_recent_failed_jobs_warn_without_failing(): void
    {
        $this->configureMpesa();
        $this->recordReconciliation(now()->subMinutes(2));
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

    private function recordReconciliation(Carbon $createdAt): void
    {
        $hospital = Hospital::withoutGlobalScopes()->firstOrFail();
        app()->instance('currentHospital', $hospital);
        $payment = MpesaPayment::factory()->create();

        DB::table('payment_events')->insert([
            'hospital_id' => $hospital->id,
            'payment_id' => $payment->id,
            'event' => 'reconciliation_result',
            'payload' => json_encode(['result' => 'test']),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
