<?php

namespace App\Console\Commands;

use App\Models\MpesaPayment;
use App\Services\PaymentLifecycleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

#[Signature('mpesa:verify-sandbox {--live : Actually fire an STK push}')]
#[Description('Verify Safaricom sandbox credentials without initiating a payment by default')]
class MpesaVerifySandboxCredentials extends Command
{
    public function handle(PaymentLifecycleService $lifecycle): int
    {
        $isLive = (bool) $this->option('live');
        if ($isLive && app()->environment('production')) {
            Log::warning('Refused to initiate a sandbox STK push in production.');
            $this->error('Live STK pushes are disabled in production.');

            return self::FAILURE;
        }

        if (config('mpesa.environment') !== 'sandbox') {
            $this->error('M-Pesa environment must be sandbox for this command.');

            return self::FAILURE;
        }

        $consumerKey = config('mpesa.consumer_key');
        $consumerSecret = config('mpesa.consumer_secret');
        $shortcode = config('mpesa.shortcode');
        $passkey = config('mpesa.passkey');
        $callbackUrl = config('mpesa.callback_url');
        $phone = config('mpesa.test_phone');

        if (! $this->isConfigured($consumerKey)
            || ! $this->isConfigured($consumerSecret)
            || ! $this->isConfigured($shortcode)
            || ! $this->isConfigured($passkey)
            || ! $this->isConfigured($callbackUrl)
        ) {
            $this->error('M-Pesa sandbox credentials or callback URL are missing.');

            return self::FAILURE;
        }

        if ($isLive && ! $this->isConfigured($phone)) {
            $this->error('MPESA_TEST_PHONE must be configured before a live STK push.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) config('mpesa.endpoints.sandbox'), '/');
        $timeout = (int) config('mpesa.timeout', 20);

        try {
            $response = Http::timeout($timeout)
                ->withBasicAuth((string) $consumerKey, (string) $consumerSecret)
                ->get($baseUrl.'/oauth/v1/generate', [
                    'grant_type' => 'client_credentials',
                ]);
            $response->throw();
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('M-Pesa sandbox OAuth verification failed.', [
                'exception_class' => $exception::class,
            ]);
            $this->error('Unable to retrieve an OAuth token from Safaricom sandbox.');

            return self::FAILURE;
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            $this->line('OAuth token retrieved: no');
            $this->error('Safaricom sandbox did not return an access token.');

            return self::FAILURE;
        }

        $this->info('OAuth token retrieved: yes');
        $this->line('Token length: '.strlen($token));
        $expiresIn = filter_var($response->json('expires_in'), FILTER_VALIDATE_INT);
        if ($expiresIn !== false && $expiresIn > 0) {
            $this->line('Token expiry: '.$expiresIn.' seconds (at '.now()->addSeconds($expiresIn)->toDateTimeString().')');
        } else {
            $this->line('Token expiry: not provided by Safaricom');
        }

        if (! $isLive) {
            $this->comment('No STK push was sent. Use --live to request explicit confirmation.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Send a real sandbox STK push to the configured test phone?')) {
            $this->warn('STK push cancelled.');

            return self::SUCCESS;
        }

        $timestamp = now()->format('YmdHis');
        $password = base64_encode((string) $shortcode.(string) $passkey.$timestamp);
        $amount = max(1, (int) config('mpesa.test_amount', 1));

        try {
            $stkResponse = Http::timeout($timeout)
                ->withToken($token)
                ->post($baseUrl.'/mpesa/stkpush/v1/processrequest', [
                    'BusinessShortCode' => (string) $shortcode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'TransactionType' => 'CustomerPayBillOnline',
                    'Amount' => $amount,
                    'PartyA' => (string) $phone,
                    'PartyB' => (string) $shortcode,
                    'PhoneNumber' => (string) $phone,
                    'CallBackURL' => (string) $callbackUrl,
                    'AccountReference' => (string) config('mpesa.account_reference'),
                    'TransactionDesc' => (string) config('mpesa.transaction_description'),
                ]);
            $stkResponse->throw();
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('M-Pesa sandbox STK verification failed.', [
                'exception_class' => $exception::class,
            ]);
            $this->error('Safaricom sandbox did not accept the STK push request.');

            return self::FAILURE;
        }

        $data = $stkResponse->json();
        $data = is_array($data) ? $data : [];
        $this->table(
            ['CheckoutRequestID', 'MerchantRequestID', 'ResponseCode', 'ResponseDescription'],
            [[
                $data['CheckoutRequestID'] ?? 'not provided',
                $data['MerchantRequestID'] ?? 'not provided',
                $data['ResponseCode'] ?? 'not provided',
                $data['ResponseDescription'] ?? 'not provided',
            ]],
        );

        if ((string) ($data['ResponseCode'] ?? '') !== '0') {
            return self::FAILURE;
        }

        $checkoutRequestId = $data['CheckoutRequestID'] ?? null;
        if (! is_string($checkoutRequestId) || $checkoutRequestId === '') {
            $this->error('Safaricom accepted the STK request without returning a CheckoutRequestID.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($lifecycle, $data, $checkoutRequestId, $phone, $amount): void {
            $payment = MpesaPayment::query()->create([
                'checkout_request_id' => $checkoutRequestId,
                'merchant_request_id' => $data['MerchantRequestID'] ?? null,
                'phone' => (string) $phone,
                'amount' => $amount,
                'account_reference' => (string) config('mpesa.account_reference'),
                'transaction_desc' => (string) config('mpesa.transaction_description'),
                'status' => MpesaPayment::STATUS_PENDING,
            ]);

            $lifecycle->recordEvent($payment, 'payment_initiated', [
                'checkout_request_id' => $checkoutRequestId,
                'amount' => $amount,
                'source' => 'sandbox_credential_verification',
            ]);
        });

        return self::SUCCESS;
    }

    private function isConfigured(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
