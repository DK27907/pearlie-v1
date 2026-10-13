<?php

namespace App\Http\Controllers;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaCallbackInbox;
use App\Models\MpesaPayment;
use App\Services\MpesaCallbackInboxService;
use App\Services\MpesaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MpesaCallbackController extends Controller
{
    public function __construct(private readonly MpesaService $mpesa) {}

    public function handleConfirmation(
        Request $request,
        MpesaCallbackInboxService $callbackInbox,
    ): JsonResponse {
        $payload = $request->all();
        $hospitalSlug = $request->route('hospitalSlug') ?? $request->query('hospital');
        $hospitalSlug = is_string($hospitalSlug) && trim($hospitalSlug) !== ''
            ? mb_substr(trim($hospitalSlug), 0, 255)
            : null;

        try {
            $inbox = $callbackInbox->receive($payload, $hospitalSlug);
        } catch (Throwable $exception) {
            Log::critical('Unable to persist an M-Pesa callback before acknowledgement.', [
                'exception' => $exception,
            ]);

            return response()->json([
                'ResultCode' => 1,
                'ResultDesc' => 'Callback was not durably recorded.',
            ], 500);
        }

        if (! $inbox->wasRecentlyCreated && $inbox->status === MpesaCallbackInbox::STATUS_PROCESSED) {
            return response()->json(['status' => 'already_processed']);
        }

        try {
            $callbackInbox->process($inbox);
        } catch (Throwable $exception) {
            Log::error('Unable to process a persisted M-Pesa callback inbox entry.', [
                'inbox_id' => $inbox->id,
                'checkout_request_id' => $inbox->checkout_request_id,
                'exception' => $exception,
            ]);
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

    public function checkStatus(Request $request, string $checkoutRequestId): JsonResponse
    {
        abort_unless(preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $checkoutRequestId), 404);

        $hospitalSlug = $request->query('hospital');
        $hospital = null;
        if ($hospitalSlug !== null) {
            abort_unless(is_string($hospitalSlug), 404);
            $hospital = Hospital::withoutGlobalScopes()
                ->where('slug', $hospitalSlug)
                ->first();
            abort_unless($hospital, 404);

            $currentHospital = hospital();
            abort_if(
                $currentHospital !== null && (int) $currentHospital->id !== (int) $hospital->id,
                404,
            );
        }

        $hadCurrentHospital = app()->bound('currentHospital');
        $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;
        if ($hospital) {
            app()->instance('currentHospital', $hospital);
        }

        try {
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
        } finally {
            if ($hadCurrentHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }
}
