<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PearlieService extends PearlieServiceV2
{
    public function __construct(
        KnowledgeBaseService $knowledgeBase,
        EscalationService $escalationService,
        DoctorAvailabilityService $availabilityService,
        private readonly MpesaService $mpesaService,
    ) {
        parent::__construct($knowledgeBase, $escalationService, $availabilityService);
    }

    public function detectPaymentIntent(string $message): bool
    {
        return (bool) preg_match('/\b(pay|mpesa|m[\s-]?pesa|deposit|payment)\b/i', $message);
    }

    /**
     * @return array{success: bool, payment_id: int, checkout_request_id: string, message: string}
     */
    public function initiatePayment(string $phone, float $amount, int $appointmentId): array
    {
        try {
            return $this->mpesaService->stkPush(
                $this->mpesaService->formatPhone($phone),
                $amount,
                'APT-'.$appointmentId,
                'Appointment Deposit',
                $appointmentId,
            );
        } catch (Throwable $exception) {
            Log::error('Unable to initiate appointment payment from the AI booking flow.', [
                'appointment_id' => $appointmentId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function processMessage(string $message, string $sessionId, string $channel = 'web'): array
    {
        $handoff = $this->escalationService->handleActivePatientMessage($sessionId, $message, $channel);
        if ($handoff !== null) {
            return $handoff;
        }

        $handoff = $this->handleEscalationIntent($message, $sessionId, $channel);
        if ($handoff !== null) {
            return $handoff;
        }

        $isBookingIntent = $this->detectDoctorBookingIntent($message);
        $isPaymentIntent = $this->detectPaymentIntent($message);
        $bookingEnabled = hospital()?->hasFeature('booking') ?? false;
        $paymentEnabled = hospital()?->hasFeature('mpesa') ?? false;

        try {
            $localAnswer = $this->knowledgeBase->search($message);
        } catch (Throwable $exception) {
            Log::error('Knowledge base search failed during the AI booking flow.', [
                'exception' => $exception,
            ]);
            $localAnswer = null;
        }

        if (! $bookingEnabled && ($isBookingIntent || Cache::has($this->pendingBookingKey($sessionId))
            || $this->hasActiveSlotSelection($sessionId))) {
            return [
                'response' => 'Online appointment booking is not included in this hospital plan yet. Please contact the hospital directly for assistance.',
                'confidence' => 1.0,
                'source' => 'subscription_feature_unavailable',
                'escalated' => false,
                'appointment_id' => null,
            ];
        }

        if (! $paymentEnabled && $isPaymentIntent) {
            return [
                'response' => 'Online M-Pesa payments are not currently enabled for this hospital. Please contact the hospital for payment options.',
                'confidence' => 1.0,
                'source' => 'subscription_feature_unavailable',
                'escalated' => false,
                'appointment_id' => null,
            ];
        }

        if ($isPaymentIntent) {
            $paymentResponse = $this->continuePayment($message, $sessionId, $channel);
            if ($paymentResponse !== null) {
                return $paymentResponse;
            }
        }

        $pendingBookingKey = $this->pendingBookingKey($sessionId);
        $pendingBooking = Cache::get($pendingBookingKey);
        if (is_array($pendingBooking) && ! $isPaymentIntent) {
            $pendingBooking = $this->mergeBookingDetails($pendingBooking, $message);
            $pendingBooking['raw_message'] = trim(($pendingBooking['raw_message'] ?? '')."\n".$message);

            return $this->handleBookingRequest($pendingBooking, $message, $sessionId, $channel);
        }

        if ($isBookingIntent) {
            $appointmentData = $this->detectAppointment($message);

            if ($appointmentData !== false) {
                return $this->handleBookingRequest($appointmentData, $message, $sessionId, $channel);
            }
        } elseif ($this->hasActiveSlotSelection($sessionId)) {
            $appointmentData = $this->parseSlotSelection($message, $sessionId);
            if ($appointmentData !== null) {
                return $this->handleBookingRequest($appointmentData, $message, $sessionId, $channel);
            }
        }

        if ($localAnswer !== null && ! $isPaymentIntent && ! $isBookingIntent) {
            Conversation::query()->create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $localAnswer,
                'confidence_score' => 0.95,
                'channel' => $channel,
            ]);

            return [
                'response' => $localAnswer,
                'confidence' => 0.95,
                'source' => 'knowledge_base',
                'escalated' => false,
                'appointment_id' => null,
            ];
        }

        return parent::processMessage($message, $sessionId, $channel);
    }

    /**
     * @param  array{phone: ?string, email?: ?string, preferred_date: ?string, name: ?string, reason: string, slot_start_time: ?string, preferred_doctor_id?: ?int}  $appointmentData
     * @return array<string, mixed>
     */
    private function handleBookingRequest(
        array $appointmentData,
        string $message,
        string $sessionId,
        string $channel,
    ): array {
        $date = $appointmentData['preferred_date'] ?? null;
        $time = $appointmentData['slot_start_time'] ?? null;
        $isSwahili = $this->isSwahiliConversation($sessionId, $message);

        if (! $date) {
            Cache::put($this->pendingBookingKey($sessionId), [
                ...$appointmentData,
                'raw_message' => $message,
            ], now()->addMinutes(30));

            $missing = [];
            if (! ($appointmentData['name'] ?? null)) {
                $missing[] = $isSwahili ? 'jina lako kamili' : 'your full name';
            }
            if (! ($appointmentData['phone'] ?? null)) {
                $missing[] = $isSwahili ? 'namba yako ya simu' : 'your phone number';
            }
            $missing[] = $isSwahili ? 'tarehe unayopendelea' : 'your preferred date';
            if (! ($appointmentData['reason'] ?? null)) {
                $missing[] = $isSwahili ? 'huduma unayohitaji' : 'the service you need';
            }
            if (! ($appointmentData['email'] ?? null)) {
                $missing[] = $isSwahili ? 'barua pepe yako' : 'your email address';
            }
            $response = $isSwahili
                ? 'Karibu, nitakusaidia kuweka miadi. Tafadhali nitumie '.implode(', ', $missing).'.'
                : 'I can help you request an appointment. Please send '.implode(', ', $missing).'.';

            return $this->recordBookingResponse($message, $sessionId, $channel, $response, null, 'booking_details');
        }

        if (! $time) {
            Cache::put($this->pendingBookingKey($sessionId), [
                ...$appointmentData,
                'raw_message' => $message,
            ], now()->addMinutes(30));

            return $this->respondWithAvailableSlots($message, $sessionId, $channel);
        }

        $missing = [];
        if (! ($appointmentData['name'] ?? null)) {
            $missing[] = $isSwahili ? 'jina lako kamili' : 'your full name';
        }
        if (! ($appointmentData['phone'] ?? null)) {
            $missing[] = $isSwahili ? 'namba yako ya simu ya mkononi' : 'your Kenyan mobile phone number';
        }
        if (! ($appointmentData['email'] ?? null)) {
            $missing[] = $isSwahili ? 'barua pepe yako' : 'your email address';
        }
        if (! ($appointmentData['reason'] ?? null)) {
            $missing[] = $isSwahili ? 'huduma unayohitaji' : 'the service you need';
        }

        if ($missing !== []) {
            Cache::put($this->pendingBookingKey($sessionId), [
                ...$appointmentData,
                'raw_message' => $message,
            ], now()->addMinutes(30));
            $response = $isSwahili
                ? 'Nimepata muda unaoweza kuomba. Tafadhali nitumie '.implode(' na ', $missing).' ili nihifadhi nafasi na kutuma ombi la M-Pesa.'
                : 'I found a time you can request. Please reply with '.implode(' and ', $missing).' so I can reserve it and send your M-Pesa prompt.';

            return $this->recordBookingResponse($message, $sessionId, $channel, $response, null, 'booking_details');
        }

        $appointment = $this->availabilityService->bookAppointment(
            $date,
            $time,
            [
                'name' => $appointmentData['name'],
                'phone' => $appointmentData['phone'],
                'email' => $appointmentData['email'],
                'reason' => $appointmentData['reason'],
                'raw_message' => $appointmentData['raw_message'] ?? $message,
                'session_id' => $sessionId,
            ],
            $appointmentData['preferred_doctor_id'] ?? null,
        );

        if (! $appointment) {
            Cache::forget($this->pendingBookingKey($sessionId));

            return $this->respondWithAvailableSlots(
                'availability '.$date,
                $sessionId,
                $channel,
                $isSwahili
                    ? 'Muda huo haupatikani tena. Hizi ndizo nafasi zilizo wazi:'
                    : 'That time is no longer available. Here are the open times instead.',
            );
        }

        Cache::forget($this->pendingBookingKey($sessionId));

        try {
            $payment = $this->initiatePayment(
                (string) $appointmentData['phone'],
                (float) pearlie_config('appointment.deposit_amount'),
                $appointment->id,
            );

            $response = $isSwahili
                ? sprintf(
                    'Ombi lako la miadi na %s tarehe %s saa %s limehifadhiwa. Tumetuma ombi la M-Pesa la KSh %s kwa %s. Weka PIN yako ya M-Pesa; miadi itathibitishwa baada ya malipo kuthibitishwa.',
                    $appointment->doctor?->name ?? 'daktari uliyemchagua',
                    $appointment->preferred_date?->toFormattedDateString() ?? $date,
                    substr((string) $appointment->slot_start_time, 0, 5),
                    number_format((float) pearlie_config('appointment.deposit_amount'), 2),
                    $this->mpesaService->formatPhone((string) $appointmentData['phone']),
                )
                : sprintf(
                    'Your appointment request with %s for %s at %s is saved. We sent an M-Pesa STK prompt for KSh %s to %s. Enter your M-Pesa PIN to complete the deposit; your appointment will be confirmed after payment is verified.',
                    $appointment->doctor?->name ?? 'your selected doctor',
                    $appointment->preferred_date?->toFormattedDateString() ?? $date,
                    substr((string) $appointment->slot_start_time, 0, 5),
                    number_format((float) pearlie_config('appointment.deposit_amount'), 2),
                    $this->mpesaService->formatPhone((string) $appointmentData['phone']),
                );
            $source = 'appointment_payment';
        } catch (Throwable $exception) {
            Log::error('Appointment was booked but its M-Pesa prompt could not be started.', [
                'appointment_id' => $appointment->id,
                'exception' => $exception,
            ]);
            $payment = null;
            $response = $isSwahili
                ? 'Ombi lako la miadi limehifadhiwa, lakini hatukuweza kutuma ombi la M-Pesa kwa sasa. Jibu “lipa” ili ujaribu tena baada ya muda mfupi.'
                : 'Your appointment request is saved, but we could not start the M-Pesa prompt right now. Reply “pay” to try again shortly.';
            $source = 'appointment_payment_failed';
        }

        return $this->recordBookingResponse(
            $message,
            $sessionId,
            $channel,
            $response,
            $appointment->id,
            $source,
            $payment,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function continuePayment(string $message, string $sessionId, string $channel): ?array
    {
        try {
            $appointment = AppointmentRequest::query()
                ->where('session_id', $sessionId)
                ->whereIn('status', [AppointmentRequest::STATUS_PENDING, AppointmentRequest::STATUS_CONFIRMED])
                ->latest('id')
                ->first();

            if (! $appointment) {
                return null;
            }

            if ($appointment->payment_status === 'paid') {
                return $this->recordBookingResponse(
                    $message,
                    $sessionId,
                    $channel,
                    'Payment for your appointment has already been received.',
                    $appointment->id,
                    'payment',
                );
            }

            if ($appointment->mpesa_checkout_request_id) {
                return $this->recordBookingResponse(
                    $message,
                    $sessionId,
                    $channel,
                    'An M-Pesa prompt has already been sent. Enter your M-Pesa PIN to complete the payment.',
                    $appointment->id,
                    'payment',
                );
            }

            $phone = $this->extractPhone($message) ?: $appointment->phone;
            if (! $phone) {
                return $this->recordBookingResponse(
                    $message,
                    $sessionId,
                    $channel,
                    'Please reply with your Kenyan mobile phone number so I can send the M-Pesa prompt.',
                    $appointment->id,
                    'payment_details',
                );
            }

            $payment = $this->initiatePayment(
                $phone,
                (float) ($appointment->payment_amount ?? pearlie_config('appointment.deposit_amount')),
                $appointment->id,
            );

            return $this->recordBookingResponse(
                $message,
                $sessionId,
                $channel,
                'An M-Pesa STK prompt has been sent to '.$this->mpesaService->formatPhone($phone).'. Enter your PIN to complete the appointment deposit.',
                $appointment->id,
                'appointment_payment',
                $payment,
            );
        } catch (Throwable $exception) {
            Log::error('Unable to continue an appointment payment from the AI conversation.', [
                'session_id' => $sessionId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>|null  $payment
     * @return array<string, mixed>
     */
    private function recordBookingResponse(
        string $message,
        string $sessionId,
        string $channel,
        string $response,
        ?int $appointmentId,
        string $source,
        ?array $payment = null,
    ): array {
        Conversation::query()->create([
            'session_id' => $sessionId,
            'user_message' => $message,
            'ai_response' => $response,
            'confidence_score' => 0.98,
            'channel' => $channel,
        ]);

        return [
            'response' => $response,
            'confidence' => 0.98,
            'source' => $source,
            'escalated' => false,
            'appointment_id' => $appointmentId,
            'payment' => $payment,
        ];
    }

    private function hasActiveSlotSelection(string $sessionId): bool
    {
        return Conversation::query()
            ->where('session_id', $sessionId)
            ->where(function ($query): void {
                $query->where('ai_response', 'like', '%Reply with your preferred time%')
                    ->orWhere('ai_response', 'like', '%Jibu kwa muda unaopendelea%');
            })
            ->exists();
    }

    /**
     * @return array{phone: ?string, preferred_date: ?string, name: ?string, reason: string, slot_start_time: ?string, preferred_doctor_id: ?int}|null
     */
    private function parseSlotSelection(string $message, string $sessionId): ?array
    {
        $time = $this->extractRequestedTime($message);
        if (! $time) {
            return null;
        }

        $date = $this->extractRequestedDate($message);
        if (! $date) {
            $availabilityResponse = Conversation::query()
                ->where('session_id', $sessionId)
                ->where(function ($query): void {
                    $query->where('ai_response', 'like', '%Reply with your preferred time%')
                        ->orWhere('ai_response', 'like', '%Jibu kwa muda unaopendelea%');
                })
                ->latest('id')
                ->value('ai_response');

            if (is_string($availabilityResponse)
                && preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $availabilityResponse, $matches)
            ) {
                $date = $matches[1];
            }
        }

        if (! $date) {
            return null;
        }

        $name = null;
        if (preg_match('/(?:my name is|jina langu ni|naitwa)\s+([\pL][\pL\'-]*(?:\s+[\pL][\pL\'-]*){0,3}?)(?=\s+(?:and\b|with\b|my phone\b|phone\b|namba yangu\b)|[,.;]|$)/iu', $message, $matches)) {
            $name = trim($matches[1]);
        }

        $preferredDoctorId = null;
        foreach (User::query()->where('is_doctor', true)->get() as $doctor) {
            if (str_contains(mb_strtolower($message), mb_strtolower($doctor->name))) {
                $preferredDoctorId = $doctor->id;
                break;
            }
        }

        return [
            'phone' => $this->extractPhone($message),
            'email' => $this->extractEmail($message),
            'preferred_date' => $date,
            'name' => $name,
            'reason' => $this->extractBookingReason($message),
            'slot_start_time' => $time,
            'preferred_doctor_id' => $preferredDoctorId,
        ];
    }

    private function extractPhone(string $message): ?string
    {
        if (preg_match('/(?:0[71]\d{8}|\+?254[71]\d{8}|[71]\d{8})/', $message, $matches)) {
            return $matches[0];
        }

        return null;
    }

    private function extractEmail(string $message): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $matches)
            && filter_var($matches[0], FILTER_VALIDATE_EMAIL)
        ) {
            return strtolower($matches[0]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $booking
     * @return array<string, mixed>
     */
    private function mergeBookingDetails(array $booking, string $message): array
    {
        $booking['email'] = $booking['email'] ?? $this->extractEmail($message);
        $booking['phone'] = $booking['phone'] ?? $this->extractPhone($message);

        if (! ($booking['name'] ?? null)
            && preg_match('/(?:my name is|jina langu ni|naitwa)\s+([\pL][\pL\'-]*(?:\s+[\pL][\pL\'-]*){0,3}?)(?=\s+(?:and\b|with\b|my phone\b|phone\b|namba yangu\b)|[,.;]|$)/iu', $message, $matches)
        ) {
            $booking['name'] = trim($matches[1]);
        }

        $booking['preferred_date'] = $booking['preferred_date'] ?? $this->extractRequestedDate($message);
        $booking['slot_start_time'] = $booking['slot_start_time'] ?? $this->extractRequestedTime($message);
        if (blank($booking['reason'] ?? null)) {
            $booking['reason'] = $this->extractBookingReason($message);
        }

        return $booking;
    }

    private function pendingBookingKey(string $sessionId): string
    {
        return 'pending_appointment_booking_'.hash('sha256', $sessionId);
    }

    private function isSwahiliConversation(string $sessionId, string $message): bool
    {
        if ($this->isSwahili($message)) {
            return true;
        }

        $lastResponse = Conversation::query()
            ->where('session_id', $sessionId)
            ->latest('id')
            ->value('ai_response');

        return is_string($lastResponse)
            && (bool) preg_match(
                '/\b(nafasi za miadi|jibu kwa muda unaopendelea|karibu, nitakusaidia|tafadhali nitumie)\b/iu',
                $lastResponse,
            );
    }
}
