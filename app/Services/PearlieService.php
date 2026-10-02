<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Service;
use Carbon\CarbonImmutable;
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

    public function processMessage(
        string $message,
        string $sessionId,
        string $channel = 'web',
        ?Service $selectedService = null,
    ): array {
        $phoneCapture = $this->continueEscalationPhoneCapture($message, $sessionId, $channel);
        if ($phoneCapture !== null) {
            return $phoneCapture;
        }

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
        if (! $selectedService
            && (! is_array($pendingBooking) || blank($pendingBooking['service_id'] ?? null))
            && ($isBookingIntent || is_array($pendingBooking))
            && ! $isPaymentIntent
        ) {
            $selectedService = $this->matchServiceFromMessage($message);
        }

        if (is_array($pendingBooking) && ! $isPaymentIntent) {
            if ($selectedService) {
                $pendingBooking = $this->applySelectedService($pendingBooking, $selectedService);
            }

            if ($pendingBooking['awaiting_confirmation'] ?? false) {
                if ($this->isBookingConfirmation($message)) {
                    if (! $this->bookingServiceIsAvailable($pendingBooking)) {
                        Cache::forget($pendingBookingKey);

                        return $this->recordBookingResponse(
                            $message,
                            $sessionId,
                            $channel,
                            'That service is no longer available. Please select an active service and start again.',
                            null,
                            'booking_service_unavailable',
                        );
                    }

                    return $this->confirmBooking($pendingBooking, $message, $sessionId, $channel);
                }

                if ($this->isBookingCancellation($message)) {
                    Cache::forget($pendingBookingKey);

                    return $this->recordBookingResponse(
                        $message,
                        $sessionId,
                        $channel,
                        'Your appointment request has been cancelled.',
                        null,
                        'booking_cancelled',
                    );
                }

                unset($pendingBooking['awaiting_confirmation']);
            }

            $pendingBooking = $this->mergeBookingDetails($pendingBooking, $message);
            if ($selectedService) {
                $pendingBooking = $this->applySelectedService($pendingBooking, $selectedService);
            } elseif ($this->isGenericServiceReason((string) ($pendingBooking['reason'] ?? ''))) {
                $pendingBooking['reason'] = '';
            }
            $pendingBooking['raw_message'] = trim(($pendingBooking['raw_message'] ?? '')."\n".$message);

            return $this->handleBookingRequest($pendingBooking, $message, $sessionId, $channel);
        }

        $isAvailabilityQuestion = preg_match('/\b(availability|available|free slots?)\b/i', $message)
            && ! preg_match('/\b(book|schedule|reserve)\b/i', $message);
        if ($isAvailabilityQuestion && ! is_array($pendingBooking)) {
            return $this->respondWithAvailableSlots($message, $sessionId, $channel);
        }

        if ($isBookingIntent) {
            $appointmentData = $this->detectAppointment($message);

            if ($appointmentData !== false) {
                if ($selectedService) {
                    $appointmentData = $this->applySelectedService($appointmentData, $selectedService);
                } elseif ($this->isGenericServiceReason((string) ($appointmentData['reason'] ?? ''))) {
                    $appointmentData['reason'] = '';
                }

                return $this->handleBookingRequest($appointmentData, $message, $sessionId, $channel);
            }
        } elseif ($this->hasActiveSlotSelection($sessionId)) {
            $appointmentData = $this->parseSlotSelection($message, $sessionId);
            if ($appointmentData !== null) {
                if ($selectedService) {
                    $appointmentData = $this->applySelectedService($appointmentData, $selectedService);
                }

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
        $pendingBookingKey = $this->pendingBookingKey($sessionId);
        $prompt = null;

        if (! $this->bookingServiceIsAvailable($appointmentData)) {
            Cache::forget($pendingBookingKey);

            return $this->recordBookingResponse(
                $message,
                $sessionId,
                $channel,
                'That service is no longer available. Please select an active service and start again.',
                null,
                'booking_service_unavailable',
            );
        }
        $service = isset($appointmentData['service_id'])
            ? Service::query()->active()->find((int) $appointmentData['service_id'])
            : null;

        if ($appointmentData['doctor_selection_needed'] ?? false) {
            $options = implode(', ', $appointmentData['doctor_options'] ?? []);
            $prompt = $isSwahili
                ? 'Tafadhali fafanua jina la daktari unayemchagua: '.$options.'.'
                : 'More than one doctor matches that preference. Please choose one: '.$options.'.';
        } elseif (blank($appointmentData['name'] ?? null)) {
            $prompt = $isSwahili
                ? 'Karibu, nitakusaidia kuweka miadi. Tafadhali niambie jina lako kamili.'
                : 'I can help you request an appointment. What is your full name?';
        } elseif (blank($appointmentData['phone'] ?? null)) {
            $prompt = $isSwahili
                ? 'Asante, '.trim((string) $appointmentData['name']).'. Tafadhali nipe namba yako ya simu.'
                : 'Thank you, '.trim((string) $appointmentData['name']).'. What phone number should we use?';
        } elseif (blank($appointmentData['reason'] ?? null)) {
            $prompt = $isSwahili
                ? 'Unahitaji huduma gani?'
                : 'Which service do you need?';
        } elseif (! $date) {
            $prompt = $isSwahili
                ? 'Unapendelea miadi tarehe gani?'
                : ($service
                    ? sprintf(
                        'Got it — booking %s (KSh %s). What date would you prefer?',
                        $service->name,
                        number_format((float) $service->price, 2),
                    )
                    : 'What date would you prefer for the appointment?');
        }

        if ($prompt !== null) {
            Cache::put($pendingBookingKey, [
                ...$appointmentData,
                'raw_message' => $message,
            ], now()->addMinutes(30));

            return $this->recordBookingResponse($message, $sessionId, $channel, $prompt, null, 'booking_details');
        }

        if (! $time) {
            Cache::put($pendingBookingKey, [
                ...$appointmentData,
                'raw_message' => $message,
            ], now()->addMinutes(30));

            return $this->respondWithAvailableSlots(
                ($isSwahili ? 'miadi ' : 'availability ').$date,
                $sessionId,
                $channel,
                preferredDoctorId: $appointmentData['preferred_doctor_id'] ?? null,
                requiredSpecialty: $service?->requires_specialty,
            );
        }

        $availability = $this->getAvailableSlotsForAI(
            'availability '.$date,
            $appointmentData['preferred_doctor_id'] ?? null,
            $service?->requires_specialty,
        );
        $selectedDoctor = collect($availability['doctors'])->first(
            fn (array $doctor): bool => (! ($appointmentData['preferred_doctor_id'] ?? null)
                || $doctor['id'] === $appointmentData['preferred_doctor_id'])
                && (bool) ($doctor['slots'][$time] ?? false),
        );

        if (! $selectedDoctor) {
            $appointmentData['slot_start_time'] = null;
            unset($appointmentData['awaiting_confirmation']);
            Cache::put($pendingBookingKey, $appointmentData, now()->addMinutes(30));

            return $this->respondWithAvailableSlots(
                ($isSwahili ? 'miadi ' : 'availability ').$date,
                $sessionId,
                $channel,
                $isSwahili
                    ? 'Muda huo haupatikani tena. Hizi ndizo nafasi zilizo wazi:'
                    : 'That time is no longer available. Here are the open times instead.',
                $appointmentData['preferred_doctor_id'] ?? null,
                $service?->requires_specialty,
            );
        }

        $appointmentData['preferred_doctor_id'] = $selectedDoctor['id'];
        Log::info('Booking doctor selected', [
            'doctor_id' => $selectedDoctor['id'],
            'state' => [
                'preferred_doctor_id' => $selectedDoctor['id'],
                'preferred_date' => $date,
                'slot_start_time' => $time,
            ],
        ]);
        $appointmentData['awaiting_confirmation'] = true;
        Cache::put($pendingBookingKey, $appointmentData, now()->addMinutes(30));

        $dateLabel = CarbonImmutable::parse($date)->toFormattedDateString();
        $depositAmount = (float) ($service?->price ?? pearlie_config('appointment.deposit_amount'));
        $response = $isSwahili
            ? sprintf(
                'Tafadhali thibitisha miadi yako: %s kwa huduma ya %s na %s tarehe %s saa %s, kwa simu %s. Jibu NDIYO kuthibitisha au HAPANA kughairi.',
                $appointmentData['name'],
                $appointmentData['reason'],
                $selectedDoctor['name'],
                $dateLabel,
                $time,
                $appointmentData['phone'],
            )
            : sprintf(
                'Please confirm your appointment: %s for %s with %s on %s at %s, using phone %s. Reply YES to confirm or NO to cancel.',
                $appointmentData['name'],
                $appointmentData['reason'],
                $selectedDoctor['name'],
                $dateLabel,
                $time,
                $appointmentData['phone'],
            );
        if (hospital()?->hasFeature('mpesa')) {
            $response .= $isSwahili
                ? ' Tutatuma ombi la M-Pesa la KSh '.number_format($depositAmount, 2).' baada ya uthibitisho wako.'
                : ' An M-Pesa prompt for KSh '.number_format($depositAmount, 2).' will be sent after you confirm.';
        }

        return $this->recordBookingResponse(
            $message,
            $sessionId,
            $channel,
            $response,
            null,
            'booking_confirmation',
        );
    }

    /**
     * @param  array<string, mixed>  $appointmentData
     * @return array<string, mixed>
     */
    private function confirmBooking(array $appointmentData, string $message, string $sessionId, string $channel): array
    {
        $date = (string) $appointmentData['preferred_date'];
        $time = (string) $appointmentData['slot_start_time'];
        $pendingBookingKey = $this->pendingBookingKey($sessionId);
        $isSwahili = $this->isSwahiliConversation($sessionId, $message);
        $service = isset($appointmentData['service_id'])
            ? Service::query()->active()->findOrFail((int) $appointmentData['service_id'])
            : null;
        $appointment = $this->availabilityService->bookAppointment(
            $date,
            $time,
            [
                'name' => $appointmentData['name'],
                'phone' => $appointmentData['phone'],
                'email' => $appointmentData['email'] ?? null,
                'reason' => $appointmentData['reason'],
                'raw_message' => $appointmentData['raw_message'] ?? $message,
                'session_id' => $sessionId,
            ],
            $appointmentData['preferred_doctor_id'] ?? null,
            $service,
        );

        if (! $appointment) {
            $appointmentData['slot_start_time'] = null;
            unset($appointmentData['awaiting_confirmation']);
            Cache::put($pendingBookingKey, $appointmentData, now()->addMinutes(30));

            return $this->respondWithAvailableSlots(
                'availability '.$date,
                $sessionId,
                $channel,
                $isSwahili
                    ? 'Muda huo haupatikani tena. Hizi ndizo nafasi zilizo wazi:'
                    : 'That time is no longer available. Here are the open times instead.',
                requiredSpecialty: $service?->requires_specialty,
            );
        }

        Cache::forget($pendingBookingKey);

        if (! hospital()?->hasFeature('mpesa')) {
            $response = $isSwahili
                ? 'Ombi lako la miadi limepokelewa. Timu ya hospitali itawasiliana nawe kuthibitisha.'
                : 'Your appointment request has been received. The hospital team will contact you to confirm.';

            return $this->recordBookingResponse(
                $message,
                $sessionId,
                $channel,
                $response,
                $appointment->id,
                'appointment_confirmed',
            );
        }

        $paymentAmount = (float) ($appointment->payment_amount ?? pearlie_config('appointment.deposit_amount'));
        if ($paymentAmount <= 0) {
            $response = $isSwahili
                ? 'Ombi lako la miadi limepokelewa. Hakuna malipo ya M-Pesa yanayohitajika kwa huduma hii.'
                : 'Your appointment request has been received. No M-Pesa payment is required for this service.';

            return $this->recordBookingResponse(
                $message,
                $sessionId,
                $channel,
                $response,
                $appointment->id,
                'appointment_confirmed',
            );
        }

        try {
            $payment = $this->initiatePayment(
                (string) $appointmentData['phone'],
                $paymentAmount,
                $appointment->id,
            );

            $response = $isSwahili
                ? sprintf(
                    'Ombi lako la miadi na %s tarehe %s saa %s limepokelewa. Tumetuma ombi la M-Pesa la KSh %s kwa %s. Weka PIN yako ya M-Pesa ili kulipa amana.',
                    $appointment->doctor?->name ?? 'daktari uliyemchagua',
                    $appointment->preferred_date?->toFormattedDateString() ?? $date,
                    substr((string) $appointment->slot_start_time, 0, 5),
                    number_format($paymentAmount, 2),
                    $this->mpesaService->formatPhone((string) $appointmentData['phone']),
                )
                : sprintf(
                    'Your appointment request with %s for %s at %s is confirmed as pending. We sent an M-Pesa STK prompt for KSh %s to %s. Enter your M-Pesa PIN to complete the deposit.',
                    $appointment->doctor?->name ?? 'your selected doctor',
                    $appointment->preferred_date?->toFormattedDateString() ?? $date,
                    substr((string) $appointment->slot_start_time, 0, 5),
                    number_format($paymentAmount, 2),
                    $this->mpesaService->formatPhone((string) $appointmentData['phone']),
                );

            return $this->recordBookingResponse(
                $message,
                $sessionId,
                $channel,
                $response,
                $appointment->id,
                'appointment_payment',
                $payment,
            );
        } catch (Throwable $exception) {
            Log::error('Appointment was confirmed but its M-Pesa prompt could not be started.', [
                'appointment_id' => $appointment->id,
                'exception' => $exception,
            ]);

            $response = $isSwahili
                ? 'Ombi lako la miadi limepokelewa, lakini hatukuweza kutuma ombi la M-Pesa kwa sasa. Jibu “lipa” ili ujaribu tena baada ya muda mfupi.'
                : 'Your appointment request is saved, but we could not start the M-Pesa prompt right now. Reply “pay” to try again shortly.';

            return $this->recordBookingResponse(
                $message,
                $sessionId,
                $channel,
                $response,
                $appointment->id,
                'appointment_payment_failed',
            );
        }
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

        $matchingDoctors = $this->matchingDoctors($message);
        $preferredDoctorId = $matchingDoctors->count() === 1
            ? $matchingDoctors->first()->id
            : null;

        return [
            'phone' => $this->extractPhone($message),
            'email' => $this->extractEmail($message),
            'preferred_date' => $date,
            'name' => $name,
            'reason' => $this->extractBookingReason($message),
            'slot_start_time' => $time,
            'preferred_doctor_id' => $preferredDoctorId,
            'doctor_selection_needed' => $matchingDoctors->count() > 1,
            'doctor_options' => $matchingDoctors->count() > 1
                ? $matchingDoctors->pluck('name')->all()
                : [],
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
        $email = $this->extractEmail($message);
        $phone = $this->extractPhone($message);
        $date = $this->extractRequestedDate($message);
        $time = $this->extractRequestedTime($message);
        $name = $this->extractBookingName($message);

        if ($email) {
            $booking['email'] = $email;
        }
        if ($phone) {
            $booking['phone'] = $phone;
        }
        if ($date) {
            $booking['preferred_date'] = $date;
        }
        if ($time) {
            $booking['slot_start_time'] = $time;
        }

        $capturedName = false;
        if ($name) {
            $booking['name'] = $name;
            $capturedName = true;
        } elseif (blank($booking['name'] ?? null)
            && ! $email
            && ! $phone
            && ! $date
            && ! $time
            && $this->isPlainBookingName($message)
        ) {
            $booking['name'] = trim($message);
            $capturedName = true;
        }

        $matchingDoctors = $this->matchingDoctors($message);
        if ($matchingDoctors->count() === 1) {
            $selectedDoctor = $matchingDoctors->first();
            $booking['preferred_doctor_id'] = $selectedDoctor->id;
            unset($booking['doctor_selection_needed']);
            Log::info('Booking doctor selected', [
                'doctor_id' => $selectedDoctor->id,
                'state' => [
                    'preferred_doctor_id' => $selectedDoctor->id,
                    'preferred_date' => $booking['preferred_date'] ?? null,
                    'slot_start_time' => $booking['slot_start_time'] ?? null,
                ],
            ]);
        } elseif ($matchingDoctors->count() > 1 && ! ($booking['preferred_doctor_id'] ?? null)) {
            $booking['doctor_selection_needed'] = true;
            $booking['doctor_options'] = $matchingDoctors->pluck('name')->all();
        }

        $reason = $this->extractBookingReason($message);
        if ($reason !== '') {
            $booking['reason'] = $reason;
        } elseif (blank($booking['reason'] ?? null)
            && ! $capturedName
            && ! $email
            && ! $phone
            && ! $date
            && ! $time
            && $this->isPlainBookingService($message)
        ) {
            $booking['reason'] = trim($message);
        }

        return $booking;
    }

    private function extractBookingName(string $message): ?string
    {
        if (preg_match('/(?:my name is|jina langu ni|naitwa)\s+([\pL][\pL\'-]*(?:\s+[\pL][\pL\'-]*){0,3}?)(?=\s+(?:and\b|with\b|my phone\b|phone\b|namba yangu\b)|[,.;]|$)/iu', $message, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function isPlainBookingName(string $message): bool
    {
        return (bool) preg_match(
            '/^[\pL][\pL\'-]*(?:\s+[\pL][\pL\'-]*){0,3}$/u',
            trim($message),
        );
    }

    private function isPlainBookingService(string $message): bool
    {
        return (bool) preg_match('/^[\pL][\pL \'-]{1,60}$/u', trim($message))
            && ! preg_match('/\b(?:i|we|want|would|like|to|book|appointment|schedule|my|name|phone|date|tomorrow|today|yes|no|ndiyo|hapana)\b/i', $message);
    }

    private function isBookingConfirmation(string $message): bool
    {
        return (bool) preg_match('/^\s*(?:yes|y|yeah|yep|confirm(?:\s+(?:the\s+)?appointment)?|ndio|ndiyo|sawa)\b[.! ]*$/iu', $message);
    }

    private function isBookingCancellation(string $message): bool
    {
        return (bool) preg_match('/^\s*(?:no|n|cancel|cancel it|hapana|sitaki)\b[.! ]*$/iu', $message);
    }

    private function pendingBookingKey(string $sessionId): string
    {
        return 'pending_appointment_booking_'.hash('sha256', $sessionId);
    }

    private function matchServiceFromMessage(string $message): ?Service
    {
        if (hospital() === null) {
            return null;
        }

        $normalizedMessage = $this->normalizeServiceText($message);
        $services = Service::query()->active()->get(['id', 'name']);
        $exactMatches = $services->filter(function (Service $service) use ($normalizedMessage): bool {
            $normalizedName = $this->normalizeServiceText($service->name);

            return $normalizedName !== ''
                && str_contains(' '.$normalizedMessage.' ', ' '.$normalizedName.' ');
        });

        if ($exactMatches->isNotEmpty()) {
            $longestNameLength = $exactMatches->map(
                fn (Service $service): int => count($this->meaningfulServiceTokens($service->name)),
            )->max();
            $longestMatches = $exactMatches->filter(
                fn (Service $service): bool => count($this->meaningfulServiceTokens($service->name)) === $longestNameLength,
            );

            return $longestMatches->count() === 1 ? $longestMatches->first() : null;
        }

        $serviceTokens = $services->mapWithKeys(
            fn (Service $service): array => [$service->id => $this->meaningfulServiceTokens($service->name)],
        );
        $tokenFrequency = $serviceTokens
            ->flatten()
            ->countBy();
        $messageTokens = array_fill_keys(explode(' ', $normalizedMessage), true);
        $matches = [];

        foreach ($services as $service) {
            $tokens = $serviceTokens[$service->id];
            if ($tokens === []) {
                continue;
            }

            $matchedTokens = array_values(array_filter(
                $tokens,
                fn (string $token): bool => isset($messageTokens[$token]),
            ));
            $hasSufficientCoverage = count($matchedTokens) >= (int) ceil(count($tokens) * 0.6);
            $hasUniquePartialMatch = count($matchedTokens) === 1
                && ($tokenFrequency[$matchedTokens[0]] ?? 0) === 1;

            if ($hasSufficientCoverage || $hasUniquePartialMatch) {
                $matches[] = [
                    'service' => $service,
                    'matched_tokens' => count($matchedTokens),
                    'coverage' => count($matchedTokens) / count($tokens),
                ];
            }
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, fn (array $left, array $right): int => [
            $right['coverage'],
            $right['matched_tokens'],
        ] <=> [
            $left['coverage'],
            $left['matched_tokens'],
        ]);

        if (isset($matches[1])
            && $matches[0]['coverage'] === $matches[1]['coverage']
            && $matches[0]['matched_tokens'] === $matches[1]['matched_tokens']
        ) {
            return null;
        }

        return $matches[0]['service'];
    }

    /**
     * @param  array<string, mixed>  $appointmentData
     * @return array<string, mixed>
     */
    private function applySelectedService(array $appointmentData, Service $service): array
    {
        $appointmentData['service_id'] = $service->id;

        if (blank($appointmentData['reason'] ?? null)
            || $this->isGenericServiceReason((string) ($appointmentData['reason'] ?? ''))
        ) {
            $appointmentData['reason'] = $service->name;
        }

        return $appointmentData;
    }

    /**
     * @return array<int, string>
     */
    private function meaningfulServiceTokens(string $serviceName): array
    {
        $ignoredTokens = [
            'and', 'for', 'the', 'with', 'service', 'appointment', 'clinic', 'hospital',
        ];

        return array_values(array_unique(array_filter(
            explode(' ', $this->normalizeServiceText($serviceName)),
            fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, $ignoredTokens, true),
        )));
    }

    private function normalizeServiceText(string $value): string
    {
        $normalized = mb_strtolower($value);

        return trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $normalized));
    }

    private function isGenericServiceReason(string $reason): bool
    {
        return (bool) preg_match(
            '/^(?:(?:a|an|the)\s+)?(?:[\pL]+\s+)?(?:appointment|service)$/iu',
            trim($reason),
        );
    }

    /**
     * @param  array<string, mixed>  $appointmentData
     */
    private function bookingServiceIsAvailable(array $appointmentData): bool
    {
        return ! isset($appointmentData['service_id'])
            || Service::query()->active()->whereKey($appointmentData['service_id'])->exists();
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
                '/\b(nafasi za miadi|jibu kwa muda unaopendelea|karibu, nitakusaidia|tafadhali nitumie|jina lako kamili|namba yako ya simu|unapendelea miadi tarehe|unahitaji huduma|jibu ndiyo)\b/iu',
                $lastResponse,
            );
    }
}
