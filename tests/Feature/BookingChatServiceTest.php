<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentNotification;
use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingChatService;
use App\Services\MpesaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BookingChatServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_service_by_full_name(): void
    {
        [$hospital, $services] = $this->createContext();

        app(BookingChatService::class)->handle('extract-service-full', 'Book Dental Cleaning', 'web');

        $this->assertSame($services[0]->id, $this->state('extract-service-full')['service_id']);
    }

    public function test_extracts_service_by_partial_name(): void
    {
        [$hospital, $services] = $this->createContext();

        app(BookingChatService::class)->handle('extract-service-partial', 'I need dental', 'web');

        $this->assertSame($services[0]->id, $this->state('extract-service-partial')['service_id']);
    }

    public function test_ambiguous_service_match_returns_null_and_requests_clarification(): void
    {
        $this->createContext(['Dental Cleaning', 'Teeth Cleaning']);

        $response = app(BookingChatService::class)->handle('extract-service-ambiguous', 'Book a cleaning', 'web');

        $this->assertNull($this->state('extract-service-ambiguous')['service_id']);
        $this->assertStringContainsString('Which service', $response->message);
    }

    public function test_unknown_service_returns_null_and_lists_available_services(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('extract-service-unknown', 'Book a rocket launch', 'web');

        $this->assertNull($this->state('extract-service-unknown')['service_id']);
        $this->assertStringContainsString('Dental Cleaning', $response->message);
    }

    public function test_extracts_doctor_by_full_name(): void
    {
        [, , $doctors] = $this->createContext();

        app(BookingChatService::class)->handle('extract-doctor-full', 'Book with Dr. Amina Njeri', 'web');

        $this->assertSame($doctors[0]->id, $this->state('extract-doctor-full')['doctor_id']);
    }

    public function test_extracts_doctor_by_last_name(): void
    {
        [, , $doctors] = $this->createContext();

        app(BookingChatService::class)->handle('extract-doctor-last', 'Book with Dr. Njeri', 'web');

        $this->assertSame($doctors[0]->id, $this->state('extract-doctor-last')['doctor_id']);
    }

    public function test_extracts_doctor_by_first_name(): void
    {
        [, , $doctors] = $this->createContext();

        app(BookingChatService::class)->handle('extract-doctor-first', 'Book with Amina', 'web');

        $this->assertSame($doctors[0]->id, $this->state('extract-doctor-first')['doctor_id']);
    }

    public function test_extracts_tomorrow_as_the_booking_date(): void
    {
        $this->travelTo('2026-10-02 08:00:00');
        $this->createContext();

        app(BookingChatService::class)->handle('extract-date-tomorrow', 'Book for tomorrow', 'web');

        $this->assertSame('2026-10-03', $this->state('extract-date-tomorrow')['date']);
    }

    public function test_extracts_next_monday_as_the_booking_date(): void
    {
        $this->travelTo('2026-10-02 08:00:00');
        $this->createContext();

        app(BookingChatService::class)->handle('extract-date-monday', 'Book next Monday', 'web');

        $this->assertSame('2026-10-05', $this->state('extract-date-monday')['date']);
    }

    public function test_extracts_an_explicit_booking_date(): void
    {
        $this->travelTo('2026-10-02 08:00:00');
        $this->createContext();

        app(BookingChatService::class)->handle('extract-date-explicit', 'Book for 2026-10-15', 'web');

        $this->assertSame('2026-10-15', $this->state('extract-date-explicit')['date']);
    }

    public function test_extracts_9am_as_the_booking_time(): void
    {
        $this->createContext();

        app(BookingChatService::class)->handle('extract-time-9am', 'Book at 9am', 'web');

        $this->assertSame('09:00', $this->state('extract-time-9am')['time']);
    }

    public function test_extracts_1430_as_the_booking_time(): void
    {
        $this->createContext();

        app(BookingChatService::class)->handle('extract-time-1430', 'Book at 14:30', 'web');

        $this->assertSame('14:30', $this->state('extract-time-1430')['time']);
    }

    public function test_extracts_name_email_and_both_kenyan_phone_formats(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);

        $chat->handle(
            'extract-contact-international',
            'Book Dental Cleaning. My name is John Doe, phone 254712345678, email patient@test.com',
            'web',
        );
        $internationalState = $chat->getState('extract-contact-international');
        $chat->handle(
            'extract-contact-local',
            'Book Dental Cleaning. My name is Jane Doe, phone 0712345678, email jane@test.com',
            'web',
        );
        $localState = $chat->getState('extract-contact-local');

        $this->assertSame('254712345678', $internationalState['patient_phone']);
        $this->assertSame('254712345678', $localState['patient_phone']);
        $this->assertSame('John Doe', $internationalState['patient_name']);
        $this->assertSame('patient@test.com', $internationalState['patient_email']);
    }

    public function test_idle_booking_intent_transitions_to_collecting(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('state-idle-collecting', 'Book dental', 'web');

        $this->assertSame('COLLECTING', $response->state);
        $this->assertSame('COLLECTING', $this->state('state-idle-collecting')['state']);
    }

    public function test_collecting_requests_a_missing_date(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('state-missing-date', 'Book Dental Cleaning', 'web');

        $this->assertStringContainsString('What date', $response->message);
        $this->assertSame('COLLECTING', $response->state);
    }

    public function test_all_fields_transition_to_confirmation_with_summary(): void
    {
        [$hospital] = $this->createContext();

        $response = app(BookingChatService::class)->handle(
            'state-all-fields',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );

        $this->assertSame('CONFIRMING', $response->state);
        $this->assertStringContainsString('Dental Cleaning', $response->message);
        $this->assertStringContainsString('Dr. Amina Njeri', $response->message);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_confirmation_yes_creates_appointment_and_starts_payment(): void
    {
        [$hospital] = $this->createContext(price: 1, mpesaEnabled: true);
        $this->configureMpesaFakes();
        $chat = app(BookingChatService::class);
        $chat->handle(
            'state-confirm-yes',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );

        $response = $chat->handle('state-confirm-yes', 'yes', 'web');

        $this->assertSame('PAYMENT_PENDING', $response->state);
        $this->assertSame(1, AppointmentRequest::query()->where('id', $response->appointmentId)->count());
        $this->assertTrue($response->paymentRequired);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/mpesa/stkpush/v1/processrequest'));
    }

    public function test_unavailable_time_offers_alternatives_on_the_same_day(): void
    {
        [, , $doctors] = $this->createContext();
        DoctorAvailability::query()
            ->where('doctor_id', $doctors[0]->id)
            ->where('day_of_week', 6)
            ->firstOrFail()
            ->update([
                'start_time' => '09:30',
                'end_time' => '11:00',
                'is_active' => true,
            ]);
        $chat = app(BookingChatService::class);
        $chat->handle(
            'alternative-same-day',
            'Book Dental Cleaning with Dr. Amina Njeri Saturday at 2pm. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );

        $response = $chat->handle('alternative-same-day', 'yes', 'web');

        $this->assertStringContainsString('09:30, 10:00, 10:30', $response->message);
        $this->assertSame('COLLECTING', $response->state);
        $this->assertSame('2026-10-03', $response->collectedFields['date']);
        $this->assertNull($response->collectedFields['time']);
        $this->assertSame($doctors[0]->id, $response->collectedFields['doctor_id']);
        $this->assertSame('Test Patient', $response->collectedFields['patient_name']);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_unavailable_date_offers_the_nearest_available_days(): void
    {
        [, , $doctors] = $this->createContext();
        DoctorAvailability::query()
            ->where('doctor_id', $doctors[0]->id)
            ->whereIn('day_of_week', [0, 6])
            ->update(['is_active' => false]);
        $chat = app(BookingChatService::class);
        $chat->handle(
            'alternative-nearest-days',
            'Book Dental Cleaning with Dr. Amina Njeri Saturday at 9am. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );

        $response = $chat->handle('alternative-nearest-days', 'yes', 'web');

        $this->assertStringContainsString('Monday October 5', $response->message);
        $this->assertStringContainsString('Tuesday October 6', $response->message);
        $this->assertStringContainsString('Wednesday October 7', $response->message);
        $this->assertSame('COLLECTING', $response->state);
        $this->assertNull($response->collectedFields['date']);
        $this->assertNull($response->collectedFields['time']);
        $this->assertSame($doctors[0]->id, $response->collectedFields['doctor_id']);
        $this->assertSame('Test Patient', $response->collectedFields['patient_name']);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_user_picks_an_alternative_slot_and_booking_succeeds(): void
    {
        [, , $doctors] = $this->createContext();
        DoctorAvailability::query()
            ->where('doctor_id', $doctors[0]->id)
            ->where('day_of_week', 6)
            ->firstOrFail()
            ->update([
                'start_time' => '09:30',
                'end_time' => '11:00',
                'is_active' => true,
            ]);
        $chat = app(BookingChatService::class);
        $chat->handle(
            'alternative-booking-success',
            'Book Dental Cleaning with Dr. Amina Njeri Saturday at 2pm. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );
        $chat->handle('alternative-booking-success', 'yes', 'web');
        $confirmation = $chat->handle('alternative-booking-success', '09:30', 'web');

        $response = $chat->handle('alternative-booking-success', 'yes', 'web');
        $appointment = AppointmentRequest::query()
            ->where('session_id', 'alternative-booking-success')
            ->firstOrFail();

        $this->assertSame('CONFIRMING', $confirmation->state);
        $this->assertSame('COMPLETED', $response->state);
        $this->assertSame('2026-10-03', CarbonImmutable::parse($appointment->preferred_date)->toDateString());
        $this->assertSame('09:30', CarbonImmutable::parse($appointment->slot_start_time)->format('H:i'));
        $this->assertSame($doctors[0]->id, $appointment->doctor_id);
        $this->assertModelExists($appointment);
    }

    public function test_doctor_selection_filters_by_service_specialty(): void
    {
        [, $services, $doctors] = $this->createContext(
            doctorNames: ['Dr. Amina Njeri', 'Dr. Brian Otieno', 'Dr. Sarah Wambui'],
        );
        $services[0]->update(['requires_specialty' => 'Dentist']);
        $doctors[0]->update(['specialization' => 'Dentist']);
        $doctors[1]->update(['specialization' => 'General']);
        $doctors[2]->update(['specialization' => 'Dentist']);

        $response = app(BookingChatService::class)->handle(
            'specialty-filter',
            'Book Dental Cleaning',
            'web',
        );

        $this->assertStringContainsString('Dr. Amina Njeri', $response->message);
        $this->assertStringContainsString('Dr. Sarah Wambui', $response->message);
        $this->assertStringNotContainsString('Dr. Brian Otieno', $response->message);
    }

    public function test_doctor_selection_falls_back_when_no_matching_specialty_exists(): void
    {
        [, $services, $doctors] = $this->createContext(
            doctorNames: ['Dr. Amina Njeri', 'Dr. Brian Otieno'],
        );
        $services[0]->update(['requires_specialty' => 'Dentist']);
        $doctors[0]->update(['specialization' => 'General']);
        $doctors[1]->update(['specialization' => 'General']);

        $response = app(BookingChatService::class)->handle(
            'specialty-fallback',
            'Book Dental Cleaning',
            'web',
        );

        $this->assertStringContainsString('No doctors with the Dentist specialty', $response->message);
        $this->assertStringContainsString('Dr. Amina Njeri', $response->message);
        $this->assertStringContainsString('Dr. Brian Otieno', $response->message);
        $this->assertSame('COLLECTING', $response->state);
        $this->assertNull($response->collectedFields['doctor_id']);

        $confirmation = app(BookingChatService::class)->handle(
            'specialty-fallback',
            'Dr. Brian Otieno tomorrow at 9am. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );
        $booking = app(BookingChatService::class)->handle('specialty-fallback', 'yes', 'web');
        $appointment = AppointmentRequest::query()
            ->where('session_id', 'specialty-fallback')
            ->firstOrFail();

        $this->assertSame('CONFIRMING', $confirmation->state);
        $this->assertSame('COMPLETED', $booking->state);
        $this->assertSame($doctors[1]->id, $appointment->doctor_id);
        $this->assertModelExists($appointment);
    }

    public function test_extract_doctor_rejects_a_mismatched_service_specialty(): void
    {
        [, $services, $doctors] = $this->createContext(
            doctorNames: ['Dr. Amina Njeri', 'Dr. Brian Otieno'],
        );
        $services[0]->update(['requires_specialty' => 'Dentist']);
        $doctors[0]->update(['specialization' => 'Dentist']);
        $doctors[1]->update(['specialization' => 'General']);

        $response = app(BookingChatService::class)->handle(
            'specialty-mismatch',
            'Book Dental Cleaning with Dr. Brian Otieno',
            'web',
        );

        $this->assertStringContainsString('Dr. Brian Otieno does not offer Dental Cleaning', $response->message);
        $this->assertStringContainsString('Dr. Amina Njeri', $response->message);
        $this->assertSame('COLLECTING', $response->state);
        $this->assertNull($response->collectedFields['doctor_id']);
    }

    public function test_service_without_specialty_shows_all_active_doctors(): void
    {
        $this->createContext(
            serviceNames: ['General Consultation'],
            doctorNames: ['Dr. Amina Njeri', 'Dr. Brian Otieno'],
        );

        $response = app(BookingChatService::class)->handle(
            'specialty-not-required',
            'Book General Consultation',
            'web',
        );

        $this->assertStringContainsString('Dr. Amina Njeri', $response->message);
        $this->assertStringContainsString('Dr. Brian Otieno', $response->message);
        $this->assertStringContainsString('Which doctor', $response->message);
    }

    public function test_confirmation_no_returns_to_idle_without_creating_appointment(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $chat->handle(
            'state-confirm-no',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );

        $response = $chat->handle('state-confirm-no', 'no', 'web');

        $this->assertSame('IDLE', $response->state);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_payment_pending_waits_and_reconciles_the_payment_callback(): void
    {
        $this->createContext(price: 1, mpesaEnabled: true);
        $this->configureMpesaFakes();
        $chat = app(BookingChatService::class);
        $chat->handle(
            'state-payment-pending',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );
        $paymentStarted = $chat->handle('state-payment-pending', 'yes', 'web');

        $waiting = $chat->handle('state-payment-pending', 'hello', 'web');
        $payment = MpesaPayment::query()
            ->where('appointment_request_id', $paymentStarted->appointmentId)
            ->firstOrFail();
        Queue::fake([SendPaymentNotification::class]);
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => $payment->checkout_request_id,
                'ResultCode' => '0',
            ]),
        ]);
        $callbackPayment = app(MpesaService::class)->handleCallback([
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => $payment->merchant_request_id,
                    'CheckoutRequestID' => $payment->checkout_request_id,
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 1],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'CHATTEST001'],
                            ['Name' => 'PhoneNumber', 'Value' => 254748249882],
                        ],
                    ],
                ],
            ],
        ]);
        $this->assertNotNull($callbackPayment);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertSame('paid', $payment->appointment->fresh()->payment_status);
        $completed = $chat->handle('state-payment-pending', 'status', 'web');

        $this->assertStringContainsString('waiting for the M-Pesa payment callback', $waiting->message);
        $this->assertSame('COMPLETED', $completed->state);
        $this->assertStringContainsString('Payment received', $completed->message);
    }

    public function test_completed_booking_resets_to_idle_on_the_next_message(): void
    {
        $this->createContext(price: 0);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Hello.']]],
            ]),
        ]);
        $chat = app(BookingChatService::class);
        $chat->handle(
            'state-completed-reset',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );
        $completed = $chat->handle('state-completed-reset', 'yes', 'web');

        $this->assertSame('COMPLETED', $completed->state);
        $nextMessage = $chat->handle('state-completed-reset', 'hello', 'web');
        $this->assertSame('IDLE', $nextMessage->state);
    }

    public function test_reset_clears_the_persisted_booking_state(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $chat->handle('state-reset', 'Book Dental Cleaning', 'web');

        $chat->reset('state-reset', 'web');

        $this->assertSame('IDLE', $chat->getState('state-reset')['state']);
        $this->assertNull($chat->getState('state-reset')['service_id']);
    }

    public function test_full_sentence_booking_confirms_without_additional_questions(): void
    {
        [, , $doctors] = $this->createContext(doctorNames: ['Dr. Amina Njeri', 'Dr. Test Live']);

        $response = app(BookingChatService::class)->handle(
            'natural-full-sentence',
            'Book a dental cleaning with Dr. Amina tomorrow at 9am. My name is Test Patient, phone 254748249882, email patient@test.com',
            'web',
        );

        $this->assertSame('CONFIRMING', $response->state);
        $this->assertSame($doctors[0]->id, $response->collectedFields['doctor_id']);
        $this->assertStringContainsString('Reply YES to confirm', $response->message);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_partial_booking_message_asks_for_service_first(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle(
            'natural-partial',
            'I want to book an appointment',
            'web',
        );

        $this->assertStringContainsString('Which service', $response->message);
        $this->assertSame('COLLECTING', $response->state);
    }

    public function test_multi_message_booking_collects_fields_in_order(): void
    {
        $this->createContext(doctorNames: ['Dr. Amina Njeri', 'Dr. Test Live']);
        $chat = app(BookingChatService::class);
        $doctorPrompt = $chat->handle('natural-multi', 'Book Dental Cleaning', 'web');
        $dateTimePrompt = $chat->handle('natural-multi', 'tomorrow at 9am', 'web');
        $contactPrompt = $chat->handle(
            'natural-multi',
            'My name is Test Patient, phone 254748249882, email patient@test.com',
            'web',
        );
        $confirmation = $chat->handle('natural-multi', 'Dr. Amina Njeri', 'web');

        $this->assertStringContainsString('Which doctor', $doctorPrompt->message);
        $this->assertStringContainsString('Which doctor', $dateTimePrompt->message);
        $this->assertStringContainsString('Which doctor', $contactPrompt->message);
        $this->assertSame('CONFIRMING', $confirmation->state);
    }

    public function test_typo_tolerant_service_matching_finds_dental_cleaning(): void
    {
        [$hospital, $services] = $this->createContext();

        app(BookingChatService::class)->handle('natural-typo', 'Book dantel cleening', 'web');

        $this->assertSame($services[0]->id, $this->state('natural-typo')['service_id']);
    }

    public function test_missing_email_is_requested_before_confirmation(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle(
            'natural-missing-email',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient, phone 254748249882',
            'web',
        );

        $this->assertStringContainsString('email address', $response->message);
        $this->assertSame('COLLECTING', $response->state);
    }

    public function test_web_channel_booking_flow_uses_persisted_state(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('channel-web', 'Book Dental Cleaning', 'web');

        $this->assertSame('COLLECTING', $response->state);
        $this->assertSame('web', Conversation::query()->where('session_id', 'channel-web')->latest('id')->value('channel'));
    }

    public function test_whatsapp_channel_uses_the_same_booking_state_machine(): void
    {
        [$hospital] = $this->createContext();

        $response = app(BookingChatService::class)->handle('254748249882', 'Book Dental Cleaning', 'whatsapp');

        $this->assertSame('COLLECTING', $response->state);
        $this->assertSame(
            '254748249882',
            $this->state('254748249882', 'whatsapp')['patient_phone'],
        );
        $this->assertSame(
            'COLLECTING',
            Conversation::query()
                ->where('session_id', 'whatsapp:254748249882')
                ->latest('id')
                ->firstOrFail()
                ->chat_state['state'],
        );
        $this->assertDatabaseHas('conversations', [
            'session_id' => 'whatsapp:254748249882',
            'channel' => 'whatsapp',
        ]);
    }

    public function test_booking_state_persists_across_service_instances(): void
    {
        $this->createContext();
        app(BookingChatService::class)->handle('persistence-instance', 'Book Dental Cleaning', 'web');

        $state = app(BookingChatService::class)->getState('persistence-instance');

        $this->assertSame('COLLECTING', $state['state']);
        $this->assertNotNull($state['service_id']);
    }

    public function test_booking_state_is_isolated_between_sessions(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $chat->handle('persistence-first', 'Book Dental Cleaning', 'web');

        $this->assertSame('COLLECTING', $chat->getState('persistence-first')['state']);
        $this->assertSame('IDLE', $chat->getState('persistence-second')['state']);
    }

    /**
     * @param  array<int, string>  $serviceNames
     * @param  array<int, string>  $doctorNames
     * @return array{0: Hospital, 1: array<int, Service>, 2: array<int, User>}
     */
    private function createContext(
        array $serviceNames = ['Dental Cleaning'],
        array $doctorNames = ['Dr. Amina Njeri'],
        float $price = 1,
        bool $mpesaEnabled = false,
    ): array {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00:00'));
        $hospital = Hospital::query()->create([
            'name' => 'Booking Chat Hospital',
            'slug' => 'booking-chat-'.fake()->unique()->slug(2),
            'subscription_plan' => $mpesaEnabled ? 'enterprise' : 'professional',
            'subscription_status' => 'active',
            'is_active' => true,
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'no_show_grace_minutes' => 30,
            'default_language' => 'en',
            'supported_languages' => ['en', 'sw'],
        ]);
        app()->instance('currentHospital', $hospital);

        $services = [];
        foreach ($serviceNames as $serviceName) {
            $services[] = $hospital->services()->create([
                'name' => $serviceName,
                'price' => $price,
                'duration_minutes' => 30,
                'category' => 'Dental',
                'is_active' => true,
            ]);
        }

        $doctors = [];
        foreach ($doctorNames as $doctorName) {
            $doctor = User::factory()->create([
                'hospital_id' => $hospital->id,
                'name' => $doctorName,
                'is_doctor' => true,
                'role' => 'doctor',
                'is_active' => true,
            ]);
            foreach (range(0, 6) as $dayOfWeek) {
                DoctorAvailability::query()->create([
                    'doctor_id' => $doctor->id,
                    'day_of_week' => $dayOfWeek,
                    'start_time' => '09:00',
                    'end_time' => '10:00',
                    'slot_duration_minutes' => 30,
                    'max_patients_per_slot' => 1,
                    'is_active' => true,
                ]);
            }
            $doctors[] = $doctor;
        }

        return [$hospital, $services, $doctors];
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $channelId, string $channel = 'web'): array
    {
        return app(BookingChatService::class)->getState($channelId, $channel);
    }

    private function configureMpesaFakes(): void
    {
        config([
            'mpesa.environment' => 'sandbox',
            'mpesa.consumer_key' => 'test-consumer',
            'mpesa.consumer_secret' => 'test-secret',
            'mpesa.passkey' => 'test-passkey',
            'mpesa.shortcode' => '174379',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
            'mpesa.timeout' => 5,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success.',
                'MerchantRequestID' => 'merchant-booking-chat',
                'CheckoutRequestID' => 'checkout-booking-chat',
            ]),
        ]);
    }
}
