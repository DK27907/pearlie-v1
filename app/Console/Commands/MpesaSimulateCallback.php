<?php

namespace App\Console\Commands;

use App\Models\MpesaPayment;
use App\Services\MpesaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mpesa:simulate-callback {checkout_request_id} {--result=0} {--receipt=MANUAL001}')]
#[Description('Simulate an M-Pesa callback for a pending local sandbox payment')]
class MpesaSimulateCallback extends Command
{
    public function handle(MpesaService $mpesa): int
    {
        if (! app()->environment('local')
            || config('mpesa.environment') !== 'sandbox'
            || ! (bool) config('mpesa.skip_callback_verification_in_local', false)
        ) {
            $this->error('Callback simulation is only available in the enabled local sandbox environment.');

            return self::FAILURE;
        }

        $resultCode = filter_var($this->option('result'), FILTER_VALIDATE_INT);
        if ($resultCode === false || $resultCode < 0) {
            $this->error('The result option must be a non-negative integer.');

            return self::FAILURE;
        }

        $checkoutRequestId = (string) $this->argument('checkout_request_id');
        $payment = MpesaPayment::withoutGlobalScopes()
            ->where('checkout_request_id', $checkoutRequestId)
            ->first();

        if (! $payment) {
            $this->error('No M-Pesa payment was found for checkout request '.$checkoutRequestId.'.');

            return self::FAILURE;
        }

        $resultDescription = $resultCode === 0
            ? 'Manual local sandbox callback succeeded.'
            : 'Manual local sandbox callback failed.';
        $payment = $mpesa->handleCallback([
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => $payment->merchant_request_id,
                    'CheckoutRequestID' => $payment->checkout_request_id,
                    'ResultCode' => $resultCode,
                    'ResultDesc' => $resultDescription,
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => $payment->amount],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => (string) $this->option('receipt')],
                            ['Name' => 'PhoneNumber', 'Value' => $payment->phone],
                        ],
                    ],
                ],
            ],
        ]);

        if (! $payment) {
            $this->error('The callback was not applied to the payment.');

            return self::FAILURE;
        }

        $this->table(
            ['id', 'checkout_request_id', 'status', 'result_code', 'mpesa_receipt', 'amount', 'phone'],
            [[
                $payment->id,
                $payment->checkout_request_id,
                $payment->status,
                $payment->result_code,
                $payment->mpesa_receipt,
                $payment->amount,
                $payment->phone,
            ]],
        );

        return self::SUCCESS;
    }
}
