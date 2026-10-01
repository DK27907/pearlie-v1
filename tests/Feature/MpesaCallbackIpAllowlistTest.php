<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MpesaCallbackIpAllowlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_ipv4_callback_passes_through(): void
    {
        $this->configureAllowlistForLocalRequests(['196.201.212.0/22']);
        Http::preventStrayRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '196.201.212.10'])
            ->postJson('/api/mpesa/callback', $this->callbackPayload('checkout-allowed'))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function test_disallowed_callback_cannot_modify_payment_or_appointment(): void
    {
        $this->configureAllowlistForLocalRequests(['196.201.212.0/22']);
        Http::preventStrayRequests();

        $hospital = Hospital::withoutGlobalScopes()->firstOrFail();
        app()->instance('currentHospital', $hospital);
        $appointment = AppointmentRequest::factory()->for($hospital)->create();
        $payment = MpesaPayment::factory()->for($appointment, 'appointment')->create([
            'checkout_request_id' => 'checkout-rejected',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->postJson('/api/mpesa/callback/'.$hospital->slug, $this->callbackPayload('checkout-rejected'))
            ->assertForbidden()
            ->assertExactJson([
                'ResultCode' => 1,
                'ResultDesc' => 'Unauthorized source',
            ]);

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'pending',
        ]);
    }

    public function test_allowlist_can_be_disabled_for_any_client_ip(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');
        config([
            'mpesa.enforce_safaricom_ip_allowlist' => false,
            'mpesa.allowlist_bypass_environments' => [],
            'mpesa.safaricom_ip_allowlist' => [],
        ]);
        Http::preventStrayRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->postJson('/api/mpesa/callback', $this->callbackPayload('checkout-disabled'))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function test_testing_environment_bypasses_the_allowlist_by_default(): void
    {
        config([
            'mpesa.enforce_safaricom_ip_allowlist' => true,
            'mpesa.allowlist_bypass_environments' => ['local', 'testing'],
            'mpesa.safaricom_ip_allowlist' => [],
        ]);
        Http::preventStrayRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->postJson('/api/mpesa/callback', $this->callbackPayload('checkout-testing'))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function test_ipv6_address_matches_an_ipv6_cidr(): void
    {
        $this->configureAllowlistForLocalRequests(['2001:db8::/32']);
        Http::preventStrayRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1::10'])
            ->postJson('/api/mpesa/callback', $this->callbackPayload('checkout-ipv6'))
            ->assertOk()
            ->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /**
     * @param  list<string>  $ranges
     */
    private function configureAllowlistForLocalRequests(array $ranges): void
    {
        app()->detectEnvironment(static fn (): string => 'local');
        config([
            'mpesa.enforce_safaricom_ip_allowlist' => true,
            'mpesa.allowlist_bypass_environments' => [],
            'mpesa.safaricom_ip_allowlist' => $ranges,
            'mpesa.environment' => 'sandbox',
            'mpesa.skip_callback_verification_in_local' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackPayload(string $checkoutRequestId): array
    {
        return [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutRequestId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'Accepted',
                ],
            ],
        ];
    }
}
