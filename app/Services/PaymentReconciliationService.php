<?php

namespace App\Services;

use App\Models\Hospital;
use App\Models\MpesaPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentReconciliationService
{
    public function __construct(
        private readonly MpesaService $mpesa,
        private readonly PaymentLifecycleService $lifecycle,
    ) {}

    public function reconcile(): int
    {
        $processed = 0;
        $hadCurrentHospital = app()->bound('currentHospital');
        $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;

        try {
            Hospital::withoutGlobalScopes()
                ->orderBy('id')
                ->chunkById(100, function ($hospitals) use (&$processed): void {
                    foreach ($hospitals as $hospital) {
                        app()->instance('currentHospital', $hospital);

                        MpesaPayment::query()
                            ->pending()
                            ->stale(90)
                            ->orderBy('id')
                            ->chunkById(100, function ($payments) use (&$processed, $hospital): void {
                                foreach ($payments as $payment) {
                                    try {
                                        $result = $this->mpesa->verifyPayment($payment->checkout_request_id);
                                        if ($this->applyProviderResult($payment, $result)) {
                                            $processed++;

                                            continue;
                                        }

                                        if ($payment->created_at->lte(now()->subMinutes(5))
                                            && $this->markTimedOut($payment)
                                        ) {
                                            $processed++;
                                        }
                                    } catch (Throwable $exception) {
                                        Log::error('Unable to reconcile a pending M-Pesa payment.', [
                                            'payment_id' => $payment->id,
                                            'hospital_id' => $hospital->id,
                                            'exception' => $exception,
                                        ]);

                                        if ($payment->created_at->lte(now()->subMinutes(5))
                                            && $this->markTimedOut($payment)
                                        ) {
                                            $processed++;
                                        }
                                    }
                                }
                            });
                    }
                });
        } finally {
            if ($hadCurrentHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }

        return $processed;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyProviderResult(MpesaPayment $payment, array $result): bool
    {
        $resultCode = filter_var($result['ResultCode'] ?? null, FILTER_VALIDATE_INT);
        if ((string) ($result['ResponseCode'] ?? '') !== '0'
            || (string) ($result['CheckoutRequestID'] ?? '') !== $payment->checkout_request_id
            || $resultCode === false
        ) {
            return false;
        }

        return DB::transaction(function () use ($payment, $result, $resultCode): bool {
            $lockedPayment = MpesaPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedPayment || ! $lockedPayment->isPending()) {
                return false;
            }

            if ($resultCode === 0) {
                $this->lifecycle->markCompleted($lockedPayment, [
                    'result_code' => 0,
                    'result_description' => $result['ResultDesc'] ?? 'Payment completed successfully.',
                    'mpesa_receipt' => $result['TransactionReceipt'] ?? null,
                    'callback_payload' => $result,
                ]);
            } else {
                $this->lifecycle->markFailed(
                    $lockedPayment,
                    (string) ($result['ResultDesc'] ?? 'M-Pesa payment failed.'),
                    $resultCode,
                    $result,
                );
            }

            $this->lifecycle->recordEvent($lockedPayment, 'reconciliation_result', $result);

            return true;
        });
    }

    private function markTimedOut(MpesaPayment $payment): bool
    {
        return $this->lifecycle->markTimeout($payment);
    }
}
