<?php

namespace App\Http\Controllers;

use App\Models\AppointmentRequest;
use App\Models\MpesaPayment;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MpesaCallbackController extends Controller
{
    public function __invoke(\Illuminate\Http\Request $request): JsonResponse
    {
        $callback = $request->input('Body.stkCallback');
        if (! is_array($callback) || empty($callback['CheckoutRequestID'])) {
            Log::warning('Received malformed M-Pesa callback.', ['payload' => $request->all()]);
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        $checkoutId = (string) $callback['CheckoutRequestID'];
        $resultCode = (int) ($callback['ResultCode'] ?? 1);
        $items = collect($callback['CallbackMetadata']['Item'] ?? []);
        $metadata = [];
        foreach ($items as $item) {
            if (isset($item['Name'])) {
                $metadata[$item['Name']] = $item['Value'] ?? null;
            }
        }

        $appointment = null;
        $shouldNotify = false;

        DB::transaction(function () use ($callback, $checkoutId, $resultCode, $metadata, &$appointment, &$shouldNotify): void {
            $payment = MpesaPayment::query()->where('checkout_request_id', $checkoutId)->lockForUpdate()->first();

            if ($payment?->processed_at) {
                $appointment = $payment->appointment;
                return;
            }

            $appointment = AppointmentRequest::query()
                ->where('mpesa_checkout_request_id', $checkoutId)
                ->lockForUpdate()
                ->first();

            $payment ??= new MpesaPayment(['checkout_request_id' => $checkoutId]);
            $payment->fill([
                'appointment_request_id' => $appointment?->id,
                'merchant_request_id' => $callback['MerchantRequestID'] ?? null,
                'phone' => $metadata['PhoneNumber'] ?? $appointment?->mpesa_phone ?? $appointment?->phone,
                'amount' => $metadata['Amount'] ?? $appointment?->booking_fee,
                'status' => $resultCode === 0 ? 'paid' : 'failed',
                'result_code' => $resultCode,
                'result_description' => $callback['ResultDesc'] ?? null,
                'mpesa_receipt' => $metadata['MpesaReceiptNumber'] ?? null,
                'callback_payload' => $callback,
                'processed_at' => now(),
            ])->save();

            if (! $appointment) {
                Log::warning('M-Pesa callback did not match an appointment.', ['checkout_request_id' => $checkoutId]);
                return;
            }

            $appointment->forceFill([
                'payment_status' => $resultCode === 0 ? 'paid' : 'failed',
                'mpesa_result_code' => $resultCode,
                'mpesa_result_description' => $callback['ResultDesc'] ?? null,
                'mpesa_receipt' => $metadata['MpesaReceiptNumber'] ?? null,
                'paid_at' => $resultCode === 0 ? now() : null,
            ])->save();

            if ($resultCode === 0 && $appointment->status !== AppointmentRequest::STATUS_CONFIRMED) {
                $appointment->forceFill(['status' => AppointmentRequest::STATUS_CONFIRMED])->save();
                $shouldNotify = true;
            }
        });

        if ($shouldNotify && $appointment) {
            $this->notifyPatient($appointment);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    private function notifyPatient(AppointmentRequest $appointment): void
    {
        $payment = $appointment->mpesaPayment()->first();
        if ($payment?->notified_at) {
            return;
        }

        $message = sprintf(
            'Hello %s, your Pearl Hospital appointment (ID %d) is confirmed. M-Pesa payment %s was received. We will contact you with the final details.',
            $appointment->name ?: 'Patient',
            $appointment->id,
            $appointment->mpesa_receipt ?: 'successfully',
        );

        $whatsappSent = false;
        $smsSent = false;
        try {
            $notifications = app(NotificationService::class);
            if ($appointment->phone) {
                $smsSent = $notifications->sendSms($appointment->phone, $message);
            }
            if ($appointment->mpesa_phone ?: $appointment->phone) {
                $whatsappSent = $notifications->sendWhatsApp($appointment->mpesa_phone ?: $appointment->phone, $message);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to notify patient after verified M-Pesa payment.', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($payment && ($whatsappSent || $smsSent)) {
            $payment->forceFill(['notified_at' => now()])->save();
        }
    }
}
