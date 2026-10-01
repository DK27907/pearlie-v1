<?php

namespace Tests\Feature;

use App\Models\MpesaPayment;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MpesaVerifySandboxCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_mode_only_requests_an_oauth_token(): void
    {
        $this->configureSandbox();
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'sandbox-access-token',
                'expires_in' => 3599,
            ]),
        ]);

        $this->artisan('mpesa:verify-sandbox')
            ->expectsOutputToContain('OAuth token retrieved: yes')
            ->expectsOutputToContain('Token length: 20')
            ->assertExitCode(0);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/oauth/v1/generate'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/mpesa/stkpush/'));
    }

    public function test_live_mode_confirms_and_sends_the_stk_payload(): void
    {
        $this->configureSandbox();
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'sandbox-access-token',
                'expires_in' => 3599,
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'CheckoutRequestID' => 'ws_CO_sandbox',
                'MerchantRequestID' => 'merchant_sandbox',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
            ]),
        ]);

        $this->artisan('mpesa:verify-sandbox', ['--live' => true])
            ->expectsConfirmation('Send a real sandbox STK push to the configured test phone?', 'yes')
            ->expectsOutputToContain('ws_CO_sandbox')
            ->assertExitCode(0);

        Http::assertSent(fn ($request): bool => str_ends_with(
            $request->url(),
            '/mpesa/stkpush/v1/processrequest',
        ) && $request['BusinessShortCode'] === '174379'
            && $request['TransactionType'] === 'CustomerPayBillOnline'
            && $request['Amount'] === 1
            && $request['PartyA'] === '254712345678'
            && $request['PhoneNumber'] === '254712345678'
            && $request['CallBackURL'] === 'https://example.test/api/mpesa/callback'
            && isset($request['Password'], $request['Timestamp']));
        $payment = MpesaPayment::query()
            ->where('checkout_request_id', 'ws_CO_sandbox')
            ->firstOrFail();
        $this->assertSame(MpesaPayment::STATUS_PENDING, $payment->status);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => 'payment_initiated',
        ]);
        $this->assertSame(
            1,
            PaymentEvent::query()->where('payment_id', $payment->id)->count(),
        );
    }

    public function test_live_mode_is_refused_in_production(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');
        $this->configureSandbox();
        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('mpesa:verify-sandbox', ['--live' => true])
            ->expectsOutputToContain('Live STK pushes are disabled in production.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    private function configureSandbox(): void
    {
        config([
            'mpesa.environment' => 'sandbox',
            'mpesa.consumer_key' => 'sandbox-consumer-key',
            'mpesa.consumer_secret' => 'sandbox-consumer-secret',
            'mpesa.passkey' => 'sandbox-passkey',
            'mpesa.shortcode' => '174379',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
            'mpesa.test_phone' => '254712345678',
            'mpesa.test_amount' => 1,
            'mpesa.appointment_deposit' => 500,
            'mpesa.account_reference' => 'TestHospital',
            'mpesa.transaction_description' => 'Sandbox verification',
            'mpesa.timeout' => 5,
        ]);
    }
}
