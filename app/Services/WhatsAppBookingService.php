<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

class WhatsAppBookingService
{
    public function __construct(
        private readonly PearlieServiceV2 $pearlie,
        private readonly MpesaService $mpesa,
    ) {
    }

    public function handle(string $from, string $message): array
    {
        $phone = $this->mpesa->normalisePhone($from);
        $sessionId = 'whatsapp:'.$phone;
        $appointment = AppointmentRequest::query()
            ->where('session_id', $sessionId)
            ->whereIn('status', [AppointmentRequest::STATUS_PENDING])
            ->latest('id')
            ->first();

        if (! $appointment && ! $this->isBookingIntent($message)) {
            return $this->pearlie->processMessage($message, $sessionId, 'whatsapp');
        }

        if (! $appointment) {
            $appointment = AppointmentRequest::create([
                'session_id' => $sessionId,
                'phone' => $phone,
                'mpesa_phone' => $phone,
                'booking_fee' => config('pearlie.booking_fee', 500),
                'raw_message' => $message,
                'status' => AppointmentRequest::STATUS_PENDING,
                'payment_status' => 'pending',
            ]);
        } else {
            $appointment->raw_message = trim($appointment->raw_message."\n".$message);
        }

        $this->extractDetails($appointment, $message);
        $appointment->save();

        $missing = $this->missingDetails($appointment);
        if ($missing !== []) {
            $response = $this->promptFor($appointment, $missing);
            return $this->recordResponse($sessionId, $message, $response, $appointment->id);
        }

        if ($appointment->payment_status === 'paid') {
            $response = 'Your appointment is already confirmed. We will contact you if anything changes.';
            return $this->recordResponse($sessionId, $message, $response, $appointment->id);
        }

        if ($appointment->mpesa_checkout_request_id) {
            $response = 'We sent an M-Pesa payment prompt to your phone. Enter your M-Pesa PIN to complete the KSh '.$appointment->booking_fee.' booking fee.';
            return $this->recordResponse($sessionId, $message, $response, $appointment->id);
        }

        try {
            $this->mpesa->initiateStkPush($appointment);
            $response = 'Thanks '.$appointment->name.'. We sent an M-Pesa prompt to '.$appointment->mpesa_phone.' for KSh '.$appointment->booking_fee.'. Enter your PIN; your appointment is confirmed only after Safaricom verifies payment.';
        } catch (\Throwable $e) {
            Log::error('Unable to initiate WhatsApp booking payment.', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
            $response = 'Your appointment details are saved as pending, but we could not start the M-Pesa prompt right now. Please try again shortly.';
        }

        return $this->recordResponse($sessionId, $message, $response, $appointment->id);
    }

    private function extractDetails(AppointmentRequest $appointment, string $message): void
    {
        $lower = strtolower($message);

        if (! $appointment->name && preg_match('/(?:my name is|name is|i am|i\'m)\s+([a-z][a-z .\'-]{1,80})/i', $message, $match)) {
            $appointment->name = trim($match[1], " \t\n\r\0\x0B.,");
        }

        if (! $appointment->preferred_date) {
            if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $message, $match)) {
                $appointment->preferred_date = $match[1];
            } elseif (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/', $message, $match)) {
                $appointment->preferred_date = sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]);
            } elseif (str_contains($lower, 'tomorrow')) {
                $appointment->preferred_date = now()->addDay()->toDateString();
            } elseif (str_contains($lower, 'today')) {
                $appointment->preferred_date = now()->toDateString();
            }
        }

        if (! $appointment->reason && preg_match('/(?:reason is|for|because)\s+(.+)/i', $message, $match)) {
            $reason = trim($match[1], " \t\n\r\0\x0B.,");
            if ($reason !== '') {
                $appointment->reason = $reason;
            }
        }

        if (! $appointment->name && $this->looksLikeName($message)) {
            $appointment->name = trim($message);
        }

        if (! $appointment->reason
            && $appointment->name
            && $appointment->preferred_date
            && ! $this->isBookingIntent($message)
            && ! $this->isDateOnly($message)
        ) {
            $appointment->reason = trim($message);
        }
    }

    private function missingDetails(AppointmentRequest $appointment): array
    {
        $missing = [];
        if (! $appointment->name) {
            $missing[] = 'full name';
        }
        if (! $appointment->preferred_date) {
            $missing[] = 'preferred date (for example 2026-10-01)';
        }
        if (! $appointment->reason) {
            $missing[] = 'reason for the visit';
        }

        return $missing;
    }

    private function promptFor(AppointmentRequest $appointment, array $missing): string
    {
        if (count($missing) === 1) {
            return 'Thank you. Please reply with your '.$missing[0].'.';
        }

        return 'I can help book that. Please reply with your '.implode(', ', $missing).'.';
    }

    private function recordResponse(string $sessionId, string $message, string $response, int $appointmentId): array
    {
        Conversation::create([
            'session_id' => $sessionId,
            'user_message' => $message,
            'ai_response' => $response,
            'confidence_score' => 0.98,
            'channel' => 'whatsapp',
        ]);

        return [
            'response' => $response,
            'confidence' => 0.98,
            'source' => 'whatsapp_booking',
            'escalated' => false,
            'appointment_id' => $appointmentId,
        ];
    }

    private function isBookingIntent(string $message): bool
    {
        return (bool) preg_match('/\b(book|booking|appointment|schedule|consultation|see a doctor)\b/i', $message);
    }

    private function looksLikeName(string $message): bool
    {
        return (bool) preg_match('/^[a-z][a-z .\'-]{1,80}$/i', trim($message))
            && ! preg_match('/\b(book|appointment|tomorrow|today|date|reason|doctor)\b/i', $message);
    }

    private function isDateOnly(string $message): bool
    {
        return (bool) preg_match('/^\s*(today|tomorrow|\d{4}-\d{2}-\d{2}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})\s*[.!]?\s*$/i', $message);
    }
}
