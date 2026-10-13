<?php

namespace App\Http\Controllers;

use App\Models\AppointmentRequest;
use App\Models\MpesaPayment;
use App\Services\MpesaService;
use App\Support\CacheLockRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MpesaCallbackController extends Controller
{
    public function __construct(private readonly MpesaService $mpesa) {}

    public function handleConfirmation(Request $request): JsonResponse
    {
        $checkoutRequestId = null;
        $payload = $request->all();
        $idempotencyKey = $this->callbackIdempotencyKey($payload);

        try {
            $checkoutRequestId = data_get($payload, 'Body.stkCallback.CheckoutRequestID');
            $checkoutRequestId = is_string($checkoutRequestId) ? $checkoutRequestId : null;

            if ($idempotencyKey !== null) {
                return CacheLockRunner::run(Cache::lock($idempotencyKey.':lock', 120), 10, function () use ($idempotencyKey, $payload): JsonResponse {
                    if (Cache::has($idempotencyKey)) {
                        return response()->json(['status' => 'already_processed']);
                    }

                    $this->mpesa->handleCallback($payload);
                    Cache::put($idempotencyKey, true, now()->addHours(24));

                    return response()->json([
                        'ResultCode' => 0,
                        'ResultDesc' => 'Accepted',
                    ]);
                });
            }

            $this->mpesa->handleCallback($payload);

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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callbackIdempotencyKey(array $payload): ?string
    {
        $callback = data_get($payload, 'Body.stkCallback');
        $checkoutRequestId = data_get($callback, 'CheckoutRequestID');
        $resultCode = data_get($callback, 'ResultCode');
        $items = data_get($callback, 'CallbackMetadata.Item', []);
        $receipt = collect($items)->firstWhere('Name', 'MpesaReceiptNumber')['Value'] ?? null;

        if (! is_string($checkoutRequestId) || ! is_numeric($resultCode)
            || ($receipt !== null && ! is_string($receipt))
        ) {
            return null;
        }

        $hash = hash('sha256', json_encode([
            $checkoutRequestId,
            $receipt,
            (string) $resultCode,
        ], JSON_THROW_ON_ERROR));

        return 'mpesa:callback:idempotency:'.$hash;
    }
}
