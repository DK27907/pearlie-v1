<?php

namespace App\Http\Controllers;

use App\Models\MpesaPayment;
use Illuminate\Http\JsonResponse;

class PaymentStatusController extends Controller
{
    public function show(int $paymentId): JsonResponse
    {
        $payment = MpesaPayment::query()
            ->with('appointment:id,status,payment_status,paid_at')
            ->findOrFail($paymentId);

        return response()->json([
            'success' => true,
            'data' => [
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'appointment_id' => $payment->appointment_request_id,
                'appointment_status' => $payment->appointment?->status,
                'receipt' => $payment->mpesa_receipt,
                'message' => match ($payment->status) {
                    MpesaPayment::STATUS_PENDING, MpesaPayment::STATUS_INITIATED => 'Payment is awaiting confirmation.',
                    MpesaPayment::STATUS_COMPLETED => 'Payment completed successfully.',
                    MpesaPayment::STATUS_FAILED => 'Payment failed.',
                    MpesaPayment::STATUS_TIMEOUT => 'Payment confirmation timed out.',
                    default => 'Payment status: '.$payment->status.'.',
                },
                'payment_status' => $payment->appointment?->payment_status,
                'paid_at' => $payment->appointment?->paid_at?->toIso8601String(),
                'processed_at' => $payment->processed_at?->toIso8601String(),
            ],
        ]);
    }
}
