<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MpesaService
{
    public function initiateStkPush(AppointmentRequest $appointment): array
    {
        $config = config('mpesa');
        $this->ensureConfigured($config);

        $phone = $this->normalisePhone($appointment->mpesa_phone ?: $appointment->phone);
        $timestamp = now()->format('YmdHis');
        $password = base64_encode($config['shortcode'].$config['passkey'].$timestamp);

        $response = Http::timeout($config['timeout'])
            ->withBasicAuth($config['consumer_key'], $config['consumer_secret'])
            ->get($this->baseUrl($config).'/oauth/v1/generate', [
                'grant_type' => 'client_credentials',
            ]);
        $response->throw();
        $token = $response->json('access_token');

        if (! $token) {
            throw new RuntimeException('M-Pesa access token was not returned.');
        }

        $stkResponse = Http::timeout($config['timeout'])
            ->withToken($token)
            ->post($this->baseUrl($config).'/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $config['shortcode'],
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => max(1, (int) ($appointment->booking_fee ?: config('pearlie.booking_fee', 500))),
                'PartyA' => $phone,
                'PartyB' => $config['shortcode'],
                'PhoneNumber' => $phone,
                'CallBackURL' => $config['callback_url'],
                'AccountReference' => $config['account_reference'],
                'TransactionDesc' => $config['transaction_description'],
            ]);
        $stkResponse->throw();

        $data = $stkResponse->json();
        if ((string) ($data['ResponseCode'] ?? '') !== '0' || empty($data['CheckoutRequestID'])) {
            throw new RuntimeException($data['ResponseDescription'] ?? 'M-Pesa STK Push could not be initiated.');
        }

        $appointment->forceFill([
            'mpesa_phone' => $phone,
            'payment_status' => 'pending',
            'mpesa_checkout_request_id' => $data['CheckoutRequestID'],
            'mpesa_merchant_request_id' => $data['MerchantRequestID'] ?? null,
            'mpesa_result_code' => null,
            'mpesa_result_description' => $data['CustomerMessage'] ?? $data['ResponseDescription'] ?? null,
        ])->save();

        return $data;
    }

    public function normalisePhone(?string $phone): string
    {
        $phone = preg_replace('/\D+/', '', (string) $phone);

        if (str_starts_with($phone, '0')) {
            return '254'.substr($phone, 1);
        }

        if (str_starts_with($phone, '7') || str_starts_with($phone, '1')) {
            return '254'.$phone;
        }

        return $phone;
    }

    private function ensureConfigured(array $config): void
    {
        foreach (['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'callback_url'] as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException('M-Pesa is not configured: missing '.$key.'.');
            }
        }
    }

    private function baseUrl(array $config): string
    {
        return $config['environment'] === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }
}
