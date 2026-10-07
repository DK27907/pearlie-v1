<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class WhatsAppBookingService
{
    public function __construct(
        private readonly PearlieServiceV2 $pearlie,
        private readonly MpesaService $mpesa,
    ) {}

    public function handle(string $from, string $message): array
    {
        $phone = $this->mpesa->normalisePhone($from);
        $sessionId = 'whatsapp:'.$phone;
        $previousState = Cache::get($this->stateCacheKey($phone));
        $language = $this->messageLanguage(
            $message,
            is_array($previousState) ? ($previousState['language'] ?? null) : null,
        );
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
                'booking_fee' => pearlie_config('appointment.deposit_amount'),
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
            $this->storeState($phone, $appointment->id, $this->stateForMissingDetails($missing), $language);
            $response = $this->promptFor($appointment, $missing, $language);

            return $this->recordResponse($sessionId, $message, $response, $appointment->id);
        }

        if ($appointment->payment_status === 'paid') {
            $this->storeState($phone, $appointment->id, 'done', $language);
            $response = $language === 'sw'
                ? 'Miadi yako tayari imethibitishwa. Tutawasiliana nawe ikiwa kutakuwa na mabadiliko.'
                : 'Your appointment is already confirmed. We will contact you if anything changes.';

            return $this->recordResponse($sessionId, $message, $response, $appointment->id);
        }

        if ($appointment->mpesa_checkout_request_id) {
            $this->storeState($phone, $appointment->id, 'await_payment', $language);
            $response = $language === 'sw'
                ? 'Tumetuma ombi la malipo ya M-Pesa kwenye simu yako. Weka PIN yako ya M-Pesa ili kulipa ada ya kuweka nafasi ya KSh '.$appointment->booking_fee.'.'
                : 'We sent an M-Pesa payment prompt to your phone. Enter your M-Pesa PIN to complete the KSh '.$appointment->booking_fee.' booking fee.';

            return $this->recordResponse($sessionId, $message, $response, $appointment->id);
        }

        try {
            $this->storeState($phone, $appointment->id, 'processing_payment', $language);
            $this->mpesa->initiateStkPush($appointment);
            $this->storeState($phone, $appointment->id, 'await_payment', $language);
            $response = $language === 'sw'
                ? 'Asante '.$appointment->name.'. Tumetuma ombi la M-Pesa kwa '.$appointment->mpesa_phone.' la KSh '.$appointment->booking_fee.'. Weka PIN yako; miadi itathibitishwa baada ya malipo kuthibitishwa.'
                : 'Thanks '.$appointment->name.'. We sent an M-Pesa prompt to '.$appointment->mpesa_phone.' for KSh '.$appointment->booking_fee.'. Enter your PIN; your appointment is confirmed only after Safaricom verifies payment.';
        } catch (\Throwable $e) {
            Log::error('Unable to initiate WhatsApp booking payment.', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
            $response = $language === 'sw'
                ? 'Maelezo ya miadi yako yamehifadhiwa yakisubiri, lakini hatukuweza kutuma ombi la M-Pesa kwa sasa. Tafadhali jaribu tena baada ya muda mfupi.'
                : 'Your appointment details are saved as pending, but we could not start the M-Pesa prompt right now. Please try again shortly.';
        }

        return $this->recordResponse($sessionId, $message, $response, $appointment->id);
    }

    public function handleMessage(string $phone, string $message): array
    {
        return $this->handle($phone, $message);
    }

    public function startBooking(string $phone): array
    {
        return $this->handleMessage($phone, 'Book an appointment');
    }

    public function processState(string $phone, string $message): array
    {
        return $this->handleMessage($phone, $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function generatePayment(string $phone, float $amount, int $appointmentId): array
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('The appointment payment amount must be positive.');
        }

        $normalizedPhone = $this->mpesa->normalisePhone($phone);
        $appointment = AppointmentRequest::query()->findOrFail($appointmentId);
        if ($appointment->phone !== $normalizedPhone) {
            throw new RuntimeException('The appointment does not belong to this WhatsApp phone number.');
        }
        if ($appointment->status !== AppointmentRequest::STATUS_PENDING
            || $appointment->payment_status === 'paid'
            || $appointment->mpesa_checkout_request_id
        ) {
            throw new RuntimeException('This appointment is not eligible for a new payment request.');
        }

        $appointment->update([
            'mpesa_phone' => $normalizedPhone,
            'booking_fee' => $amount,
        ]);
        $state = Cache::get($this->stateCacheKey($normalizedPhone));
        $language = is_array($state)
            ? ($state['language'] ?? (string) pearlie_config('ai.default_language', 'en'))
            : (string) pearlie_config('ai.default_language', 'en');
        $this->storeState($normalizedPhone, $appointment->id, 'processing_payment', $language);

        try {
            $result = $this->mpesa->initiateStkPush($appointment->refresh());
            $this->storeState($normalizedPhone, $appointment->id, 'await_payment', $language);

            return $result;
        } catch (\Throwable $exception) {
            Log::error('Unable to generate a WhatsApp appointment payment request.', [
                'appointment_id' => $appointment->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function clearState(string $phone): void
    {
        $normalizedPhone = $this->mpesa->normalisePhone($phone);
        $appointment = AppointmentRequest::query()
            ->where('session_id', 'whatsapp:'.$normalizedPhone)
            ->where('status', AppointmentRequest::STATUS_PENDING)
            ->latest('id')
            ->first();

        if ($appointment && $appointment->mpesa_checkout_request_id) {
            throw new RuntimeException('The booking cannot be cleared while an M-Pesa request is pending.');
        }

        if ($appointment) {
            $appointment->update([
                'status' => AppointmentRequest::STATUS_CANCELLED,
                'payment_status' => 'unpaid',
            ]);
        }

        Cache::forget($this->stateCacheKey($normalizedPhone));
    }

    private function extractDetails(AppointmentRequest $appointment, string $message): void
    {
        $detailsMessage = trim((string) preg_replace(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            '',
            $message,
        ));
        $lower = mb_strtolower($detailsMessage);

        if (! $appointment->email && preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $match)) {
            $email = strtolower($match[0]);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $appointment->email = $email;
            }
        }

        if (! $appointment->name && preg_match('/(?:my name is|name is|i am|i\'m|jina langu ni|naitwa)\s+([\pL][\pL\'-]*(?:\s+[\pL][\pL\'-]*){0,3})(?=\s+(?:and|with|tomorrow|today|kesho|leo|for|because|my phone|namba yangu|sababu)\b|[,.;]|$)/iu', $detailsMessage, $match)) {
            $appointment->name = trim($match[1], " \t\n\r\0\x0B.,");
        }

        if (! $appointment->preferred_date) {
            if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $detailsMessage, $match)) {
                $appointment->preferred_date = $match[1];
            } elseif (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/', $detailsMessage, $match)) {
                $appointment->preferred_date = sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]);
            } elseif (str_contains($lower, 'tomorrow') || str_contains($lower, 'kesho')) {
                $appointment->preferred_date = now()->addDay()->toDateString();
            } elseif (str_contains($lower, 'today') || str_contains($lower, 'leo')) {
                $appointment->preferred_date = now()->toDateString();
            }
        }

        if (! $appointment->reason && preg_match('/(?:reason is|for|because|sababu ni|ninahitaji|kwa ajili ya)\s+(.+)/iu', $detailsMessage, $match)) {
            $reason = trim($match[1], " \t\n\r\0\x0B.,");
            if ($reason !== '') {
                $appointment->reason = $reason;
            }
        }

        if (! $appointment->name && $this->looksLikeName($detailsMessage)) {
            $appointment->name = trim($detailsMessage);
        }

        if (! $appointment->reason
            && $appointment->name
            && $appointment->preferred_date
            && ! $this->isBookingIntent($detailsMessage)
            && ! $this->isDateOnly($detailsMessage)
        ) {
            $appointment->reason = $detailsMessage;
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
        if (! $appointment->email) {
            $missing[] = 'email address for appointment updates';
        }

        return $missing;
    }

    private function promptFor(AppointmentRequest $appointment, array $missing, string $language): string
    {
        if ($language === 'sw') {
            $translations = [
                'full name' => 'jina lako kamili',
                'preferred date (for example 2026-10-01)' => 'tarehe unayopendelea (kwa mfano 2026-10-01)',
                'reason for the visit' => 'huduma unayohitaji',
                'email address for appointment updates' => 'barua pepe kwa taarifa za miadi',
            ];
            $requestedDetails = array_map(
                static fn (string $detail): string => $translations[$detail] ?? $detail,
                $missing,
            );

            return count($requestedDetails) === 1
                ? 'Asante. Tafadhali tuma '.$requestedDetails[0].'.'
                : 'Karibu, nitakusaidia kuweka miadi. Tafadhali tuma '.implode(', ', $requestedDetails).'.';
        }

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
        return (bool) preg_match('/\b(book|booking|appointment|schedule|consultation|see a doctor|miadi|weka\s+miadi|kuweka\s+miadi|naomba\s+miadi|daktari)\b/iu', $message);
    }

    private function looksLikeName(string $message): bool
    {
        return (bool) preg_match('/^[\pL][\pL .\'-]{1,80}$/iu', trim($message))
            && ! preg_match('/\b(book|appointment|tomorrow|today|date|reason|doctor|miadi|dharura|huduma|kesho|leo)\b/iu', $message);
    }

    private function isDateOnly(string $message): bool
    {
        return (bool) preg_match('/^\s*(today|tomorrow|leo|kesho|\d{4}-\d{2}-\d{2}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})\s*[.!]?\s*$/iu', $message);
    }

    private function stateForMissingDetails(array $missing): string
    {
        return match ($missing[0]) {
            'full name' => 'ask_name',
            'preferred date (for example 2026-10-01)' => 'ask_date',
            'reason for the visit' => 'ask_service',
            'email address for appointment updates' => 'ask_email',
            default => 'confirm_details',
        };
    }

    private function storeState(string $phone, int $appointmentId, string $state, string $language): void
    {
        Cache::put($this->stateCacheKey($phone), [
            'state' => $state,
            'appointment_id' => $appointmentId,
            'language' => $language,
        ], now()->addDay());
    }

    private function messageLanguage(string $message, ?string $previousLanguage): string
    {
        if (preg_match('/\b(habari|hujambo|sijambo|jambo|mambo|vipi|niaje|shikamoo|salama|poa|miadi|daktari|dharura|tafadhali|asante|naitwa|jina langu|kesho|leo|huduma)\b/iu', $message)) {
            return 'sw';
        }

        if (preg_match('/\b(hello|hi|book|appointment|schedule|tomorrow|today|please|thank you|my name is|service)\b/i', $message)) {
            return 'en';
        }

        return $previousLanguage ?? (string) pearlie_config('ai.default_language', 'en');
    }

    private function stateCacheKey(string $phone): string
    {
        return 'whatsapp_booking_'.$phone;
    }
}
