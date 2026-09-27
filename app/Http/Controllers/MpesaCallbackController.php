<?php

namespace App\Http\Controllers;

use App\Models\AppointmentRequest;
use App\Models\MpesaPayment;
use App\Services\MpesaService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MpesaCallbackController extends Controller
{
    public function __construct(
        private readonly MpesaService $mpesa,
        private readonly WhatsAppService $whatsapp,
    ) {}

    public function handleConfirmation(Request $request): JsonResponse
    {
        $payment = $this->mpesa->handleCallback($request->all());

        if ($payment?->isCompleted() && $payment->appointment && ! $payment->notified_at) {
            try {
                if ($this->whatsapp->sendAppointmentConfirmation($payment->appointment)) {
                    $payment->forceFill(['notified_at' => now()])->save();
                }
            } catch (Throwable $exception) {
                Log::error('Unable to send WhatsApp confirmation for completed M-Pesa payment.', [
                    'payment_id' => $payment->id,
                    'appointment_id' => $payment->appointment_request_id,
                    'exception' => $exception,
                ]);
            }
        }

        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ]);
    }

    /**
     * Retains support for Daraja callback URLs configured before the API route.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return $this->handleConfirmation($request);
    }

    public function checkStatus(string $checkoutRequestId): JsonResponse
    {
        abort_unless(preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $checkoutRequestId), 404);

        $paymentExists = MpesaPayment::query()
            ->where('checkout_request_id', $checkoutRequestId)
            ->exists();
        $appointmentExists = AppointmentRequest::query()
            ->where('mpesa_checkout_request_id', $checkoutRequestId)
            ->exists();

        abort_unless($paymentExists || $appointmentExists, 404);

        return response()->json([
            'success' => true,
            'data' => $this->mpesa->verifyPayment($checkoutRequestId),
        ]);
    }
}
