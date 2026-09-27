<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\MpesaPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class MpesaService
{
    public function getAccessToken(): ?string
    {
        try {
            $config = $this->configForCurrentHospital();
            $this->ensureCredentials($config);

            $response = Http::timeout($config['timeout'] ?? 20)
                ->withBasicAuth($config['consumer_key'], $config['consumer_secret'])
                ->get($this->baseUrl($config).'/oauth/v1/generate?grant_type=client_credentials');
            $response->throw();

            $token = $response->json('access_token');

            return is_string($token) && $token !== '' ? $token : null;
        } catch (Throwable $exception) {
            Log::error('Unable to obtain the M-Pesa access token.', [
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @return array{success: bool, payment_id: int, checkout_request_id: string, message: string}
     */
    public function stkPush(
        string $phone,
        float $amount,
        string $accountReference,
        string $transactionDesc,
        ?int $appointmentId = null,
    ): array {
        try {
            if (hospital() && ! hospital()->hasFeature('mpesa')) {
                throw new RuntimeException('M-Pesa payments are not enabled for this hospital plan.');
            }

            if (! is_finite($amount) || $amount <= 0 || round($amount) < 1) {
                throw new InvalidArgumentException('The M-Pesa amount must be greater than zero.');
            }

            $amount = (float) round($amount);
            $config = $this->configForCurrentHospital();
            $this->ensureCredentials($config);
            $formattedPhone = $this->formatPhone($phone);
            $token = $this->getAccessToken();

            if (! $token) {
                throw new RuntimeException('M-Pesa did not return an access token.');
            }

            $shortcode = (string) $config['shortcode'];
            $timestamp = now()->format('YmdHis');
            $password = base64_encode($shortcode.$config['passkey'].$timestamp);
            $checkoutResponse = Http::timeout($config['timeout'] ?? 20)
                ->withToken($token)
                ->post($this->baseUrl($config).'/mpesa/stkpush/v1/processrequest', [
                    'BusinessShortCode' => $shortcode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'TransactionType' => 'CustomerPayBillOnline',
                    'Amount' => (int) $amount,
                    'PartyA' => $formattedPhone,
                    'PartyB' => $shortcode,
                    'PhoneNumber' => $formattedPhone,
                    'CallBackURL' => $config['callback_url'],
                    'AccountReference' => $accountReference,
                    'TransactionDesc' => $transactionDesc,
                ]);
            $checkoutResponse->throw();

            $responseData = $checkoutResponse->json();
            if (! is_array($responseData)
                || (string) ($responseData['ResponseCode'] ?? '') !== '0'
                || empty($responseData['CheckoutRequestID'])
            ) {
                throw new RuntimeException(
                    $responseData['ResponseDescription'] ?? 'M-Pesa could not initiate the STK push.',
                );
            }

            $payment = DB::transaction(function () use (
                $appointmentId,
                $formattedPhone,
                $amount,
                $accountReference,
                $transactionDesc,
                $responseData,
            ): MpesaPayment {
                $appointment = $appointmentId === null
                    ? null
                    : AppointmentRequest::query()->lockForUpdate()->findOrFail($appointmentId);

                $payment = MpesaPayment::query()->create([
                    'appointment_request_id' => $appointment?->id,
                    'checkout_request_id' => $responseData['CheckoutRequestID'],
                    'merchant_request_id' => $responseData['MerchantRequestID'] ?? null,
                    'phone' => $formattedPhone,
                    'amount' => $amount,
                    'account_reference' => $accountReference,
                    'transaction_desc' => $transactionDesc,
                    'status' => MpesaPayment::STATUS_PENDING,
                ]);

                if ($appointment) {
                    $appointment->forceFill([
                        'mpesa_phone' => $formattedPhone,
                        'mpesa_checkout_request_id' => $responseData['CheckoutRequestID'],
                        'mpesa_merchant_request_id' => $responseData['MerchantRequestID'] ?? null,
                        'mpesa_result_code' => null,
                        'mpesa_result_description' => $responseData['CustomerMessage']
                            ?? $responseData['ResponseDescription']
                            ?? null,
                        'payment_status' => 'pending',
                        'payment_amount' => $amount,
                    ])->save();
                }

                return $payment;
            });

            return [
                'success' => true,
                'payment_id' => $payment->id,
                'checkout_request_id' => $payment->checkout_request_id,
                'message' => $responseData['CustomerMessage']
                    ?? $responseData['ResponseDescription']
                    ?? 'An M-Pesa payment prompt was sent to your phone.',
            ];
        } catch (Throwable $exception) {
            Log::error('Unable to initiate an M-Pesa STK push.', [
                'appointment_id' => $appointmentId,
                'amount' => $amount,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function handleCallback(array $callbackData): ?MpesaPayment
    {
        try {
            $callback = $callbackData['Body']['stkCallback'] ?? null;
            if (! is_array($callback) || empty($callback['CheckoutRequestID'])) {
                Log::warning('Received a malformed M-Pesa callback.', [
                    'payload_keys' => array_keys($callbackData),
                ]);

                return null;
            }

            $checkoutRequestId = (string) $callback['CheckoutRequestID'];
            $resultCode = filter_var($callback['ResultCode'] ?? null, FILTER_VALIDATE_INT);
            if ($resultCode === false) {
                Log::warning('Received an M-Pesa callback without a valid result code.', [
                    'checkout_request_id' => $checkoutRequestId,
                ]);

                return null;
            }

            $metadata = collect($callback['CallbackMetadata']['Item'] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item) && isset($item['Name']))
                ->mapWithKeys(fn (array $item): array => [$item['Name'] => $item['Value'] ?? null])
                ->all();

            $existingPayment = MpesaPayment::query()
                ->where('checkout_request_id', $checkoutRequestId)
                ->first();

            if ($existingPayment && ! $existingPayment->isPending()) {
                return $existingPayment->load('appointment');
            }

            $appointmentExists = AppointmentRequest::query()
                ->where('mpesa_checkout_request_id', $checkoutRequestId)
                ->exists();

            if (! $existingPayment && ! $appointmentExists) {
                Log::warning('M-Pesa callback did not match a payment.', [
                    'checkout_request_id' => $checkoutRequestId,
                ]);

                return null;
            }

            $providerResult = $this->verifyPayment($checkoutRequestId);
            $providerResultCode = filter_var($providerResult['ResultCode'] ?? null, FILTER_VALIDATE_INT);
            if ((string) ($providerResult['ResponseCode'] ?? '') !== '0'
                || (string) ($providerResult['CheckoutRequestID'] ?? '') !== $checkoutRequestId
                || $providerResultCode === false
                || $providerResultCode !== $resultCode
            ) {
                Log::warning('M-Pesa callback did not match the verified provider status.', [
                    'checkout_request_id' => $checkoutRequestId,
                    'callback_result_code' => $resultCode,
                    'provider_result_code' => $providerResultCode === false ? null : $providerResultCode,
                ]);

                return null;
            }

            return DB::transaction(function () use (
                $callbackData,
                $callback,
                $checkoutRequestId,
                $resultCode,
                $metadata,
            ): ?MpesaPayment {
                $payment = MpesaPayment::query()
                    ->where('checkout_request_id', $checkoutRequestId)
                    ->lockForUpdate()
                    ->first();

                if (! $payment) {
                    $appointment = AppointmentRequest::query()
                        ->where('mpesa_checkout_request_id', $checkoutRequestId)
                        ->lockForUpdate()
                        ->first();

                    if (! $appointment) {
                        Log::warning('M-Pesa callback did not match a payment.', [
                            'checkout_request_id' => $checkoutRequestId,
                        ]);

                        return null;
                    }

                    $phone = $appointment->mpesa_phone ?: $appointment->phone;
                    if (! $phone) {
                        Log::error('M-Pesa callback matched an appointment without a payment phone number.', [
                            'appointment_id' => $appointment->id,
                            'checkout_request_id' => $checkoutRequestId,
                        ]);

                        return null;
                    }

                    $payment = MpesaPayment::query()->create([
                        'appointment_request_id' => $appointment->id,
                        'checkout_request_id' => $checkoutRequestId,
                        'merchant_request_id' => $callback['MerchantRequestID'] ?? null,
                        'phone' => $phone,
                        'amount' => $appointment->payment_amount
                            ?? $appointment->booking_fee
                            ?? pearlie_config('appointment.deposit_amount'),
                        'account_reference' => null,
                        'transaction_desc' => null,
                        'status' => MpesaPayment::STATUS_PENDING,
                    ]);
                }

                if ($payment->isCompleted() || $payment->isFailed()) {
                    return $payment->load('appointment');
                }

                $payment->merchant_request_id = $callback['MerchantRequestID']
                    ?? $payment->merchant_request_id;
                $payment->callback_payload = $callbackData;

                if ($resultCode === 0) {
                    $payment->markAsCompleted([
                        'result_code' => $resultCode,
                        'result_description' => $callback['ResultDesc'] ?? 'Payment completed successfully.',
                        'mpesa_receipt' => $metadata['MpesaReceiptNumber'] ?? null,
                        'callback_payload' => $callbackData,
                    ]);
                } else {
                    $payment->markAsFailed(
                        (string) ($callback['ResultDesc'] ?? 'M-Pesa payment failed.'),
                        $resultCode,
                    );
                    $payment->callback_payload = $callbackData;
                    $payment->save();
                }

                $appointment = $payment->appointment;
                if ($appointment) {
                    $appointment->forceFill([
                        'payment_status' => $resultCode === 0 ? 'paid' : 'unpaid',
                        'payment_amount' => $payment->amount,
                        'mpesa_result_code' => $resultCode,
                        'mpesa_result_description' => $callback['ResultDesc'] ?? null,
                        'mpesa_receipt' => $metadata['MpesaReceiptNumber'] ?? null,
                        'paid_at' => $resultCode === 0 ? now() : null,
                        'mpesa_checkout_request_id' => $resultCode === 0
                            ? $appointment->mpesa_checkout_request_id
                            : null,
                        'mpesa_merchant_request_id' => $resultCode === 0
                            ? $appointment->mpesa_merchant_request_id
                            : null,
                        ...($resultCode === 0 ? ['status' => AppointmentRequest::STATUS_CONFIRMED] : []),
                    ])->save();
                }

                return $payment->load('appointment');
            });
        } catch (Throwable $exception) {
            Log::error('Unable to process an M-Pesa payment callback.', [
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyPayment(string $checkoutRequestId): array
    {
        try {
            if (hospital() && ! hospital()->hasFeature('mpesa')) {
                throw new RuntimeException('M-Pesa payments are not enabled for this hospital plan.');
            }

            $config = $this->configForCurrentHospital();
            $this->ensureCredentials($config);
            $token = $this->getAccessToken();

            if (! $token) {
                throw new RuntimeException('M-Pesa did not return an access token.');
            }

            $timestamp = now()->format('YmdHis');
            $shortcode = (string) $config['shortcode'];
            $password = base64_encode($shortcode.$config['passkey'].$timestamp);

            $response = Http::timeout($config['timeout'] ?? 20)
                ->withToken($token)
                ->post($this->baseUrl($config).'/mpesa/stkpushquery/v1/query', [
                    'BusinessShortCode' => $shortcode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'CheckoutRequestID' => $checkoutRequestId,
                ]);
            $response->throw();

            $result = $response->json();
            if (! is_array($result)) {
                throw new RuntimeException('M-Pesa returned an invalid payment status response.');
            }

            return $result;
        } catch (Throwable $exception) {
            Log::error('Unable to verify M-Pesa payment status.', [
                'checkout_request_id' => $checkoutRequestId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function formatPhone(string $phone): string
    {
        try {
            $digits = preg_replace('/\D+/', '', $phone) ?? '';

            if (preg_match('/^0([71]\d{8})$/', $digits, $matches)) {
                return '254'.$matches[1];
            }

            if (preg_match('/^([71]\d{8})$/', $digits, $matches)) {
                return '254'.$matches[1];
            }

            if (preg_match('/^254[71]\d{8}$/', $digits)) {
                return $digits;
            }

            throw new InvalidArgumentException('Enter a valid Kenyan mobile phone number.');
        } catch (Throwable $exception) {
            Log::error('Unable to format an M-Pesa phone number.', [
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function normalisePhone(?string $phone): string
    {
        try {
            return $this->formatPhone((string) $phone);
        } catch (Throwable $exception) {
            Log::error('Unable to normalise a legacy M-Pesa phone number.', [
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * Backwards-compatible entry point used by WhatsApp appointment bookings.
     *
     * @return array{success: bool, payment_id: int, checkout_request_id: string, message: string}
     */
    public function initiateStkPush(AppointmentRequest $appointment): array
    {
        try {
            return $this->stkPush(
                (string) ($appointment->mpesa_phone ?: $appointment->phone),
                (float) ($appointment->booking_fee ?: pearlie_config('appointment.deposit_amount')),
                (string) $this->configForCurrentHospital()['account_reference'],
                (string) pearlie_config(
                    'mpesa.transaction_description',
                    pearlie_config('hospital.name').' appointment booking',
                ),
                $appointment->id,
            );
        } catch (Throwable $exception) {
            Log::error('Unable to initiate a legacy appointment STK push.', [
                'appointment_id' => $appointment->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    private function ensureCredentials(array $config): void
    {
        foreach (['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'callback_url'] as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException('M-Pesa is not configured: missing '.$key.'.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function configForCurrentHospital(): array
    {
        $config = (array) config('mpesa');
        $hospital = hospital();

        if (! $hospital) {
            return $config;
        }

        if ($hospital->slug !== 'pearl') {
            $config['consumer_key'] = $hospital->mpesa_consumer_key;
            $config['consumer_secret'] = $hospital->mpesa_consumer_secret;
            $config['passkey'] = $hospital->mpesa_passkey;
            $config['shortcode'] = $hospital->mpesa_shortcode;
        } else {
            foreach ([
                'consumer_key' => $hospital->mpesa_consumer_key,
                'consumer_secret' => $hospital->mpesa_consumer_secret,
                'passkey' => $hospital->mpesa_passkey,
                'shortcode' => $hospital->mpesa_shortcode,
            ] as $key => $value) {
                if (filled($value)) {
                    $config[$key] = $value;
                }
            }
        }

        $config['appointment_deposit'] = (float) $hospital->deposit_amount;
        $config['account_reference'] = 'MEDI'.Str::upper(Str::substr(Str::replace('-', '', $hospital->slug), 0, 8));
        $callbackUrl = (string) ($config['callback_url'] ?? '');
        if ($callbackUrl !== '') {
            $separator = str_contains($callbackUrl, '?') ? '&' : '?';
            $config['callback_url'] = $callbackUrl.$separator.http_build_query(['hospital' => $hospital->slug]);
        }

        return $config;
    }

    private function baseUrl(array $config): string
    {
        $environment = $config['environment'] ?? 'sandbox';
        $baseUrl = $config['endpoints'][$environment] ?? null;

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('The configured M-Pesa environment is invalid.');
        }

        return rtrim($baseUrl, '/');
    }
}
