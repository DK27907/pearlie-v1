<?php

namespace App\Http\Controllers;

use App\Models\AppointmentRequest;
use App\Models\MpesaPayment;
use App\Services\MpesaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MpesaCallbackController extends Controller
{
    public function __construct(private readonly MpesaService $mpesa) {}

    public function handleConfirmation(Request $request): JsonResponse
    {
        $checkoutRequestId = null;

        try {
            $checkoutRequestId = data_get($request->all(), 'Body.stkCallback.CheckoutRequestID');
            $checkoutRequestId = is_string($checkoutRequestId) ? $checkoutRequestId : null;
            $this->mpesa->handleCallback($request->all());

            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted',
            ]);
        } catch (Throwable $exception) {
            Log::error('M-Pesa callback controller exception', [
                'checkout_request_id' => $checkoutRequestId,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted',
            ]);
        }
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
