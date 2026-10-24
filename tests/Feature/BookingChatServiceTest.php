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
use App\Services\Chat\ChatResponse;
use App\Services\MpesaService;
use App\Services\PearlieServiceV2;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_chat_recognizes_a_service_from_a_distinctive_partial_message(): void
    {
        [, $services] = $this->createContext([
            'CT Scan (Head/Chest/Abdomen)',
            'Ultrasound Scan (Obstetric)',
        ]);

        app(BookingChatService::class)->handle('extract-service-distinctive-partial', 'I need obstetric', 'web');

        $this->assertSame($services[1]->id, $this->state('extract-service-distinctive-partial')['service_id']);
    }

    public function test_ambiguous_service_match_returns_null_and_requests_clarification(): void
    {
        $this->createContext(['Dental Cleaning', 'Teeth Cleaning']);

        $response = app(BookingChatService::class)->handle('extract-service-ambiguous', 'Book a cleaning', 'web');

        $this->assertNull($this->state('extract-service-ambiguous')['service_id']);
        $this->assertStringContainsString('What service do you need', $response->message);
    }

    public function test_unknown_service_returns_null_and_lists_available_services(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('extract-service-unknown', 'Book a rocket launch', 'web');

        $this->assertNull($this->state('extract-service-unknown')['service_id']);
        $this->assertStringContainsString('Dental Cleaning', $response->message);
    }

    public function test_i_want_to_book_asks_for_service_not_phone(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('want-book', 'I want to book an appointment', 'web');

        $this->assertStringContainsString('What service do you need', $response->message);
        $this->assertStringNotContainsString('health worker', mb_strtolower($response->message));
        $this->assertStringNotContainsString('phone number', mb_strtolower($response->message));
    }

    public function test_specialist_question_lists_the_matching_doctor_without_starting_a_booking(): void
    {
        [, , $doctors] = $this->createContext(
            doctorNames: ['Dr. Samuel Kiptoo', 'Dr. Brian Otieno'],
        );
        $doctors[0]->update(['specialization' => 'Oncologist']);
        $doctors[1]->update(['specialization' => 'General Practitioner']);

        $response = app(BookingChatService::class)->handle(
            'specialist-question-oncologist',
            'do you have an oncologist?',
            'web',
        );

        $this->assertSame('hospital_doctors', $response->source);
        $this->assertSame('IDLE', $response->state);
        $this->assertStringContainsString('Dr. Samuel Kiptoo (Oncologist)', $response->message);
        $this->assertStringNotContainsString('Dr. Brian Otieno', $response->message);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_service_offer_question_confirms_catalog_entry_without_starting_a_booking(): void
    {
        $this->createContext(['IVF & Fertility Consultation']);

        $response = app(BookingChatService::class)->handle(
            'service-offer-ivf',
            'do you offer IVF?',
            'web',
        );

        $this->assertSame('IDLE', $response->state);
        $this->assertSame('hospital_services', $response->source);
        $this->assertStringContainsString('IVF & Fertility Consultation', $response->message);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_booking_state_machine_survives_repeated_intent_during_active_flow(): void
    {
        [, $services] = $this->createContext();
        $sessionId = 'repeated-booking-intent';
        $chat = app(BookingChatService::class);
        Http::preventStrayRequests();

        app(PearlieServiceV2::class)->handleEscalationIntent('help me', $sessionId, 'web');

        $servicePrompt = $chat->handle($sessionId, 'I want to book an appointment', 'web');
        $datePrompt = $chat->handle($sessionId, 'Dental Cleaning', 'web');
        $namePrompt = $chat->handle($sessionId, '15/10/2026', 'web');
        $phonePrompt = $chat->handle($sessionId, 'Test Patient', 'web');
        $timePrompt = $chat->handle($sessionId, '0748249882', 'web');
        $restartPrompt = $chat->handle($sessionId, 'I want to book an appointment', 'web');
        $restartedDatePrompt = $chat->handle($sessionId, 'Dental Cleaning', 'web');
        $restartedNamePrompt = $chat->handle($sessionId, 'tomorrow', 'web');

        $this->assertStringContainsString('What service do you need', $servicePrompt->message);
        $this->assertStringContainsString('DD/MM/YYYY', $datePrompt->message);
        $this->assertStringContainsString('full name', mb_strtolower($namePrompt->message));
        $this->assertStringContainsString('mobile number', mb_strtolower($phonePrompt->message));
        $this->assertStringContainsString('What time', $timePrompt->message);
        $this->assertSame('IDLE', $restartPrompt->state);
        $this->assertStringContainsString('start over', mb_strtolower($restartPrompt->message));
        $this->assertNull($restartPrompt->collectedFields['service_id']);
        $this->assertSame($services[0]->id, $restartedDatePrompt->collectedFields['service_id']);
        $this->assertStringContainsString('DD/MM/YYYY', $restartedDatePrompt->message);
        $this->assertSame('COLLECTING', $restartedNamePrompt->state);
        $this->assertSame(now()->addDay()->toDateString(), $restartedNamePrompt->collectedFields['date']);
        $this->assertStringContainsString('full name', mb_strtolower($restartedNamePrompt->message));
        $this->assertDatabaseCount('escalations', 0);
    }

    public function test_booking_intent_restarts_after_service_was_selected(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $sessionId = 'booking-restart-after-service';

        $chat->handle($sessionId, 'I want to book an appointment', 'web');
        $serviceResponse = $chat->handle($sessionId, 'Dental Cleaning', 'web');
        $restart = $chat->handle($sessionId, 'I want to book an appointment', 'web');

        $this->assertStringContainsString('What date would you prefer', $serviceResponse->message);
        $this->assertSame('IDLE', $restart->state);
        $this->assertSame('booking_reset', $restart->source);
        $this->assertNull($restart->collectedFields['service_id']);
        $this->assertNull($restart->collectedFields['doctor_id']);
        $this->assertNull($restart->collectedFields['date']);
        $this->assertStringContainsString("Let's start over. What service do you need?", $restart->message);
    }

    public function test_booking_collects_name_and_phone_before_showing_all_summary_fields(): void
    {
        [, $services, $doctors] = $this->createContext();
        $chat = app(BookingChatService::class);
        $sessionId = 'booking-summary-fields';

        $chat->handle($sessionId, 'Book Dental Cleaning', 'web');
        $namePrompt = $chat->handle($sessionId, '15/10/2026', 'web');
        $phonePrompt = $chat->handle($sessionId, 'Test Patient', 'web');
        $timePrompt = $chat->handle($sessionId, '254748249882', 'web');
        $summary = $chat->handle($sessionId, '9am', 'web');

        $this->assertStringContainsString('full name', mb_strtolower($namePrompt->message));
        $this->assertStringContainsString('mobile number', mb_strtolower($phonePrompt->message));
        $this->assertStringContainsString('What time', $timePrompt->message);
        $this->assertSame('CONFIRMING', $summary->state);
        $this->assertSame($services[0]->id, $summary->collectedFields['service_id']);
        $this->assertSame($doctors[0]->id, $summary->collectedFields['doctor_id']);
        $this->assertSame('2026-10-15', $summary->collectedFields['date']);
        $this->assertSame('09:00', $summary->collectedFields['time']);
        $this->assertSame('Test Patient', $summary->collectedFields['patient_name']);
        $this->assertSame('254748249882', $summary->collectedFields['patient_phone']);
        foreach (['Dental Cleaning', 'Dr. Amina Njeri', 'Oct 15, 2026', '09:00', 'Test Patient', '254748249882'] as $field) {
            $this->assertStringContainsString($field, $summary->message);
        }
    }

    public function test_booking_intent_restarts_a_confirming_flow(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $sessionId = 'confirming-booking-restart';

        $chat->handle(
            $sessionId,
            'Book Dental Cleaning tomorrow at 9am. My name is Test Patient. My phone is 254748249882.',
            'web',
        );
        $confirmation = $chat->getState($sessionId);
        $restart = $chat->handle($sessionId, 'I want to book an appointment', 'web');

        $this->assertSame('CONFIRMING', $confirmation['state']);
        $this->assertSame('IDLE', $restart->state);
        $this->assertSame('booking_reset', $restart->source);
        $this->assertNull($restart->collectedFields['service_id']);
        $this->assertNull($restart->collectedFields['date']);
        $this->assertNull($restart->collectedFields['patient_name']);
        $this->assertNull($restart->collectedFields['patient_phone']);
        $this->assertStringContainsString("Let's start over", $restart->message);
    }

    public static function bookingDateInputs(): array
    {
        return [
            'tomorrow' => ['tomorrow', '2026-10-03'],
            'today' => ['today', '2026-10-02'],
            'kesho' => ['kesho', '2026-10-03'],
            'leo' => ['leo', '2026-10-02'],
            'day month year' => ['15/10/2026', '2026-10-15'],
            'year month day' => ['2026-10-15', '2026-10-15'],
            'hyphenated day month year' => ['15-10-2026', '2026-10-15'],
        ];
    }

    #[DataProvider('bookingDateInputs')]
    public function test_date_field_accepts_supported_formats_without_escalating(
        string $dateInput,
        string $expectedDate,
    ): void {
        $this->createContext();
        Http::preventStrayRequests();
        $chat = app(BookingChatService::class);
        $sessionId = 'booking-date-'.str_replace(' ', '-', $dateInput);
        $chat->handle($sessionId, 'Book Dental Cleaning', 'web');

        $response = $chat->handle($sessionId, $dateInput, 'web');

        $this->assertSame($expectedDate, $response->collectedFields['date']);
        $this->assertSame('COLLECTING', $response->state);
        $this->assertDatabaseCount('escalations', 0);
    }

    public function test_unrecognized_date_stays_in_booking_and_explains_supported_formats(): void
    {
        $this->createContext();
        Http::preventStrayRequests();
        $chat = app(BookingChatService::class);
        $sessionId = 'invalid-booking-date';
        $chat->handle($sessionId, 'Book Dental Cleaning', 'web');

        $response = $chat->handle($sessionId, '32/13/2026', 'web');

        $this->assertSame('COLLECTING', $response->state);
        $this->assertNull($response->collectedFields['date']);
        $this->assertStringContainsString("I couldn't understand '32/13/2026'", $response->message);
        $this->assertStringContainsString('DD/MM/YYYY', $response->message);
        $this->assertStringContainsString('tomorrow', $response->message);
        $this->assertDatabaseCount('escalations', 0);
    }

    public function test_ambiguous_short_date_does_not_become_booking_time(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $sessionId = 'ambiguous-short-date';
        $chat->handle($sessionId, 'Book Dental Cleaning', 'web');

        $ambiguousDate = $chat->handle($sessionId, '03/04', 'web');
        $validDate = $chat->handle($sessionId, 'tomorrow', 'web');

        $this->assertNull($ambiguousDate->collectedFields['date']);
        $this->assertNull($ambiguousDate->collectedFields['time']);
        $this->assertSame(CarbonImmutable::tomorrow()->toDateString(), $validDate->collectedFields['date']);
        $this->assertNull($validDate->collectedFields['time']);
        $this->assertStringContainsString('full name', mb_strtolower($validDate->message));
    }

    public function test_third_unrecognized_booking_message_resets_to_recovery_menu(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $sessionId = 'third-unrecognized-booking';
        $chat->handle($sessionId, 'Book Dental Cleaning', 'web');

        $chat->handle($sessionId, 'purple giraffe', 'web');
        $chat->handle($sessionId, 'purple giraffe', 'web');
        $recoveryMenu = $chat->handle($sessionId, 'purple giraffe', 'web');

        $this->assertSame('IDLE', $recoveryMenu->state);
        $this->assertSame(0, $recoveryMenu->collectedFields['unrecognized_message_count']);
        $this->assertNull($recoveryMenu->collectedFields['service_id']);
        $this->assertStringContainsString('Let me start over', $recoveryMenu->message);
        $this->assertStringContainsString('Reply book', $recoveryMenu->message);
        $this->assertDatabaseCount('escalations', 0);
    }

    public function test_three_idle_nonsense_messages_show_recovery_without_handoff(): void
    {
        $this->createContext();
        Http::preventStrayRequests();
        $chat = app(BookingChatService::class);
        $sessionId = 'three-idle-nonsense-messages';

        $firstResponse = $chat->handle($sessionId, 'blah', 'web');
        $secondResponse = $chat->handle($sessionId, 'blah', 'web');
        $recoveryMenu = $chat->handle($sessionId, 'blah', 'web');

        $this->assertStringContainsString("Sorry, I didn't catch that", $firstResponse->message);
        $this->assertStringContainsString("Sorry, I didn't catch that", $secondResponse->message);
        $this->assertSame(1, $firstResponse->collectedFields['unrecognized_message_count']);
        $this->assertSame(2, $secondResponse->collectedFields['unrecognized_message_count']);
        $this->assertSame('IDLE', $recoveryMenu->state);
        $this->assertSame(0, $recoveryMenu->collectedFields['unrecognized_message_count']);
        $this->assertStringContainsString('Let me start over', $recoveryMenu->message);
        $this->assertDatabaseCount('escalations', 0);
    }

    public function test_swahili_name_is_collected_before_phone_and_date_hint_is_complete(): void
    {
        [, $services, , $chat, $sessionId, $datePrompt, $namePrompt, $phonePrompt, $timePrompt] = $this->advanceToSwahiliBookingTimeStep();
        $confirmation = $chat->handle($sessionId, '9am', 'web');

        $this->assertStringContainsString('DD/MM/YYYY (mfano 15/10/2026), YYYY-MM-DD', $datePrompt->message);
        $this->assertStringContainsString('kesho', $datePrompt->message);
        $this->assertStringContainsString('jina lako', mb_strtolower($namePrompt->message));
        $this->assertSame('Juma', $phonePrompt->collectedFields['patient_name']);
        $this->assertStringContainsString('nambari gani ya simu', mb_strtolower($phonePrompt->message));
        $this->assertStringContainsString('saa ngapi', mb_strtolower($timePrompt->message));
        $this->assertSame($services[0]->id, $confirmation->collectedFields['service_id']);
        $this->assertSame('Juma', $confirmation->collectedFields['patient_name']);
        $this->assertSame('254712345678', $confirmation->collectedFields['patient_phone']);
        $this->assertSame('CONFIRMING', $confirmation->state);
        $this->assertStringContainsString('Tafadhali thibitisha', $confirmation->message);
    }

    #[DataProvider('swahiliNaturalTimeProvider')]
    public function test_swahili_natural_time_is_parsed_in_booking_flow(
        string $input,
        string $expectedTime,
    ): void {
        [, , $doctor, $chat, $sessionId] = $this->advanceToSwahiliBookingTimeStep();
        DoctorAvailability::query()->where('doctor_id', $doctor->id)->update([
            'start_time' => '00:00',
            'end_time' => '23:30',
        ]);

        $response = $chat->handle($sessionId, $input, 'web');

        if (in_array($expectedTime, ['03:00', '19:00'], true)) {
            $displayTime = $expectedTime === '03:00' ? '3:00 AM' : '7:00 PM';

            $this->assertStringContainsString($displayTime, $response->message);
            $this->assertStringContainsString('Saa zake za kazi ni 8:00 AM hadi 5:00 PM', $response->message);
            $this->assertNull($response->collectedFields['time']);

            return;
        }

        $this->assertSame('CONFIRMING', $response->state);
        $this->assertSame($expectedTime, $response->collectedFields['time']);
        $this->assertStringContainsString($expectedTime, $response->message);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function swahiliNaturalTimeProvider(): array
    {
        return [
            'morning digit' => ['9 asubuhi', '09:00'],
            'morning explicit' => ['saa 3 asubuhi', '03:00'],
            'midday' => ['12 mchana', '12:00'],
            'afternoon' => ['3 mchana', '15:00'],
            'evening' => ['7 jioni', '19:00'],
            'still accepts 24h' => ['09:00', '09:00'],
            'still accepts am/pm' => ['9:30 am', '09:30'],
        ];
    }

    public function test_cancelling_mid_booking_clears_fields_without_creating_an_escalation(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $sessionId = 'cancel-mid-booking';
        $chat->handle($sessionId, 'Book Dental Cleaning', 'web');
        $chat->handle($sessionId, '15/10/2026', 'web');

        $cancelled = $chat->handle($sessionId, 'cancel', 'web');

        $this->assertSame('CANCELLED', $cancelled->state);
        $this->assertNull($cancelled->collectedFields['service_id']);
        $this->assertNull($cancelled->collectedFields['date']);
        $this->assertDatabaseCount('slot_holds', 0);
        $this->assertDatabaseCount('escalations', 0);
    }

    public function test_yes_without_pending_confirmation_explains_no_confirmation_exists(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('yes-without-confirmation', 'yes', 'web');

        $this->assertStringContainsString(
            'no booking waiting for confirmation',
            $response->message,
        );
        $this->assertSame('IDLE', $response->state);
    }

    public function test_typoed_booking_request_starts_the_booking_flow(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle(
            'typo-booking',
            'I want to bok an apointment',
            'web',
        );

        $this->assertSame('COLLECTING', $response->state);
        $this->assertStringContainsString('What service do you need', $response->message);
    }

    public function test_typoed_service_name_is_recognized_during_booking(): void
    {
        [, $services] = $this->createContext(['Dental Checkup & Cleaning', 'General Consultation']);
        $chat = app(BookingChatService::class);
        $chat->handle('typo-service', 'I want to book an appointment', 'web');

        $chat->handle('typo-service', 'dntl cleanin', 'web');

        $this->assertSame($services[0]->id, $this->state('typo-service')['service_id']);
    }

    public function test_typoed_greeting_is_recognized(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('typo-greeting', 'helo', 'web');

        $this->assertStringContainsString('Hello!', $response->message);
    }

    public function test_swahili_booking_request_starts_booking_without_creating_an_appointment(): void
    {
        $this->createContext();
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'swahili-booking-intent',
            'Ninataka kuweka miadi',
            'web',
        );

        $this->assertSame('COLLECTING', $response->state);
        $this->assertSame('sw', $response->collectedFields['language']);
        $this->assertStringContainsString('Unahitaji huduma gani?', $response->message);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_slang_and_swahili_doctor_questions_use_the_local_doctor_answer(): void
    {
        [, , $doctors] = $this->createContext();
        $doctors[0]->update(['specialization' => 'Dentist']);
        Http::preventStrayRequests();

        foreach ([
            'do u have a dentst',
            'gimme a dentist',
            'hey can i see a doc',
            'daktari yupi yuko',
            'doctor',
        ] as $index => $message) {
            $response = app(BookingChatService::class)->handle(
                'doctor-question-'.$index,
                $message,
                'web',
            );

            $this->assertSame('hospital_doctors', $response->source, $message);
            $this->assertStringContainsString('Dr. Amina Njeri', $response->message);
            $this->assertSame('IDLE', $response->state);
        }
    }

    public function test_typos_in_location_question_use_the_current_hospital_address(): void
    {
        [$hospital] = $this->createContext();
        $hospital->forceFill(['address' => 'Updated clinic address, Nyahururu'])->save();
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'typo-location-question',
            'whre are you locatd',
            'web',
        );

        $this->assertSame('hospital_information', $response->source);
        $this->assertStringContainsString('Updated clinic address, Nyahururu', $response->message);
    }

    public function test_emergency_messages_override_intent_and_preserve_booking_fields(): void
    {
        [$hospital, $services] = $this->createContext();
        $hospital->forceFill(['emergency_phone' => '0700111222'])->save();
        Http::preventStrayRequests();
        $chat = app(BookingChatService::class);

        foreach ([
            'chest pain',
            "I can't breathe",
            'dharura',
            'nina maumivu makali',
        ] as $index => $message) {
            $sessionId = 'emergency-during-booking-'.$index;
            $booking = $chat->handle($sessionId, 'Book Dental Cleaning', 'web');
            $emergency = $chat->handle($sessionId, $message, 'web');
            $state = $chat->getState($sessionId);

            $this->assertSame('COLLECTING', $booking->state);
            $this->assertSame('emergency', $emergency->source);
            $this->assertStringContainsString('0700111222', $emergency->message);
            $this->assertSame('COLLECTING', $state['state']);
            $this->assertSame($services[0]->id, $state['service_id']);
        }

        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_greetings_out_of_scope_and_nonverbal_messages_stay_local(): void
    {
        $this->createContext();
        Http::preventStrayRequests();
        $chat = app(BookingChatService::class);

        foreach (['yo', 'sup', 'whats up', 'mambo vipi', 'shikamoo'] as $index => $message) {
            $greeting = $chat->handle('slang-greeting-'.$index, $message, 'web');

            $this->assertSame('hospital_greeting', $greeting->source, $message);
            $this->assertSame('IDLE', $greeting->state);
        }

        foreach (['capital of France', 'who is the president', 'tell me a joke'] as $index => $message) {
            $outOfScope = $chat->handle('out-of-scope-'.$index, $message, 'web');

            $this->assertStringContainsString('Booking Chat Hospital', $outOfScope->message);
            $this->assertSame('IDLE', $outOfScope->state);
        }

        foreach (['?', '...', '😀😀😀'] as $index => $message) {
            $clarification = $chat->handle('nonverbal-message-'.$index, $message, 'web');

            $this->assertSame('clarification', $clarification->source);
            $this->assertStringContainsString('using words', $clarification->message);
            $this->assertSame('IDLE', $clarification->state);
        }
    }

    public function test_booking_does_not_trigger_escalation(): void
    {
        [, $services] = $this->createContext();

        $response = app(BookingChatService::class)->handle('book-no-escalation', 'Book a dental cleaning', 'web');

        $this->assertSame($services[0]->id, $this->state('book-no-escalation')['service_id']);
        $this->assertStringNotContainsString('health worker', mb_strtolower($response->message));
        $this->assertStringNotContainsString('Available services:', $response->message);
    }

    public function test_booking_recovers_from_a_stale_handoff_and_preserves_collected_fields(): void
    {
        $this->travelTo('2026-10-02 08:00:00');
        [, $services, $doctors] = $this->createContext(doctorNames: ['Dr. Amina Njeri']);
        $sessionId = 'booking-state-machine-reproducer';
        $chat = app(BookingChatService::class);
        Http::preventStrayRequests();

        app(PearlieServiceV2::class)->handleEscalationIntent('help me', $sessionId, 'web');

        $servicePrompt = $chat->handle($sessionId, 'I want to book an appointment', 'web');
        $datePrompt = $chat->handle($sessionId, 'Dental Cleaning', 'web');
        $namePrompt = $chat->handle($sessionId, '15/10/2026', 'web');
        $phonePrompt = $chat->handle($sessionId, 'My name is Test Patient', 'web');
        $timePrompt = $chat->handle($sessionId, '254748249882', 'web');
        $confirmation = $chat->handle($sessionId, '9am', 'web');
        $state = $chat->getState($sessionId);

        $this->assertStringContainsString('What service do you need', $servicePrompt->message);
        $this->assertSame($services[0]->id, $state['service_id']);
        $this->assertSame($doctors[0]->id, $state['doctor_id']);
        $this->assertStringContainsString('What date would you prefer', $datePrompt->message);
        $this->assertStringContainsString('full name', mb_strtolower($namePrompt->message));
        $this->assertStringContainsString('mobile number', mb_strtolower($phonePrompt->message));
        $this->assertStringContainsString('What time would you prefer', $timePrompt->message);
        $this->assertSame('2026-10-15', $state['date']);
        $this->assertSame('09:00', $state['time']);
        $this->assertSame('Test Patient', $state['patient_name']);
        $this->assertSame('254748249882', $state['patient_phone']);
        $this->assertSame('CONFIRMING', $confirmation->state);
        $this->assertStringContainsString('Oct 15, 2026', $confirmation->message);
        $this->assertStringContainsString('Dr. Amina Njeri', $confirmation->message);
        $this->assertStringContainsString('Test Patient', $confirmation->message);
        $this->assertStringContainsString('254748249882', $confirmation->message);
        $this->assertDatabaseCount('escalations', 0);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_unrecognized_messages_during_booking_recover_without_losing_state_or_escalating(): void
    {
        [, $services] = $this->createContext();
        $chat = app(BookingChatService::class);
        $chat->handle('unrecognized-booking-input', 'Book Dental Cleaning', 'web');

        $firstClarification = $chat->handle('unrecognized-booking-input', 'purple giraffe', 'web');
        $recovery = $chat->handle('unrecognized-booking-input', 'purple giraffe', 'web');
        $state = $chat->getState('unrecognized-booking-input');

        $this->assertStringContainsString('What date would you prefer', $firstClarification->message);
        $this->assertStringContainsString("Sorry, I didn't catch that", $firstClarification->message);
        $this->assertStringContainsString('I still need a date', $recovery->message);
        $this->assertStringContainsString("Sorry, I didn't catch that", $recovery->message);
        $this->assertStringNotContainsString('Available services at', $recovery->message);
        $this->assertSame('COLLECTING', $state['state']);
        $this->assertSame($services[0]->id, $state['service_id']);
        $this->assertNull($state['date']);
        $this->assertDatabaseCount('escalations', 0);

        $dateResponse = $chat->handle('unrecognized-booking-input', 'tomorrow', 'web');

        $this->assertSame(CarbonImmutable::tomorrow()->toDateString(), $dateResponse->collectedFields['date']);
    }

    public function test_explicit_handoff_during_booking_preserves_fields_when_phone_is_captured(): void
    {
        [, $services] = $this->createContext();
        $chat = app(BookingChatService::class);
        $chat->handle('booking-explicit-handoff', 'Book Dental Cleaning', 'web');

        $handoffPrompt = $chat->handle('booking-explicit-handoff', 'I need a human', 'web');
        $handoffCreated = $chat->handle('booking-explicit-handoff', 'My phone is 254748249882', 'web');
        $continued = $chat->handle('booking-explicit-handoff', 'continue booking', 'web');

        $this->assertStringContainsString('best phone number', mb_strtolower($handoffPrompt->message));
        $this->assertFalse($handoffCreated->collectedFields['escalation_pending']);
        $this->assertSame($services[0]->id, $handoffCreated->collectedFields['service_id']);
        $this->assertSame('254748249882', $handoffCreated->collectedFields['patient_phone']);
        $this->assertDatabaseHas('escalations', ['session_id' => 'booking-explicit-handoff']);
        $this->assertSame('COLLECTING', $continued->state);
        $this->assertSame($services[0]->id, $continued->collectedFields['service_id']);
        $this->assertStringContainsString('What date would you prefer', $continued->message);
    }

    public function test_handoff_and_booking_state_roll_back_together_when_state_persistence_fails(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $sessionId = 'booking-handoff-atomicity';
        $chat->handle($sessionId, 'Book Dental Cleaning', 'web');
        $chat->handle($sessionId, 'I need a human', 'web');
        $stateBeforeHandoff = $chat->getState($sessionId);

        Event::listen('eloquent.creating: '.Conversation::class, function (Conversation $conversation): void {
            if ($conversation->user_message === 'My phone is 254748249882') {
                throw new \RuntimeException('Simulated conversation persistence failure.');
            }
        });

        $failed = false;
        try {
            $chat->handle($sessionId, 'My phone is 254748249882', 'web');
        } catch (\RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Simulated conversation persistence failure.';
        }

        $this->assertTrue($failed);
        $this->assertDatabaseCount('escalations', 0);
        $this->assertSame($stateBeforeHandoff, $chat->getState($sessionId));
        $this->assertSame('COLLECTING', $stateBeforeHandoff['state']);
        $this->assertNull($stateBeforeHandoff['patient_phone']);
        $this->assertTrue($stateBeforeHandoff['escalation_pending']);
    }

    public function test_swahili_handoff_preserves_booking_fields_and_language(): void
    {
        [, $services] = $this->createContext();
        Http::preventStrayRequests();
        $chat = app(BookingChatService::class);
        $sessionId = 'swahili-booking-handoff';

        $chat->handle($sessionId, 'Nataka kuweka miadi', 'web');
        $chat->handle($sessionId, 'Dental Cleaning', 'web');
        $handoff = $chat->handle($sessionId, 'Nataka kuongea na mhudumu', 'web');
        $phonePromptState = $chat->getState($sessionId);
        $phoneResponse = $chat->handle($sessionId, '254748249882', 'web');

        $this->assertStringContainsString('shiriki namba yako ya simu', mb_strtolower($handoff->message));
        $this->assertSame('COLLECTING', $phonePromptState['state']);
        $this->assertSame('sw', $phonePromptState['language']);
        $this->assertSame($services[0]->id, $phonePromptState['service_id']);
        $this->assertTrue($phonePromptState['escalation_pending']);
        $this->assertSame('sw', $phoneResponse->collectedFields['language']);
        $this->assertSame($services[0]->id, $phoneResponse->collectedFields['service_id']);
        $this->assertSame('254748249882', $phoneResponse->collectedFields['patient_phone']);
        $this->assertFalse($phoneResponse->collectedFields['escalation_pending']);
        $this->assertDatabaseCount('escalations', 1);
    }

    public function test_explicit_human_request_triggers_escalation(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('explicit-human', 'I need a human', 'web');

        $this->assertStringContainsString('best phone number', mb_strtolower($response->message));
        $this->assertStringNotContainsString('What service do you need', $response->message);
    }

    public function test_service_recognized_from_ct_abbreviation(): void
    {
        [, $services] = $this->createContext([
            'CT Scan (Head/Chest/Abdomen)',
            'Ultrasound Scan (Obstetric)',
        ]);

        $response = app(BookingChatService::class)->handle('ct-abbrev', 'CT', 'web');

        $this->assertSame($services[0]->id, $this->state('ct-abbrev')['service_id']);
        $this->assertStringContainsString($services[0]->name, $response->message);
        $this->assertStringContainsString('What date would you prefer', $response->message);
    }

    public function test_tenant_chat_response_names_selected_service_before_requesting_date(): void
    {
        [$hospital, $services] = $this->createContext([
            'CT Scan (Head/Chest/Abdomen)',
            'Ultrasound Scan (Obstetric)',
        ]);

        $response = $this->postJson(route('tenant.chat.post', $hospital->slug), [
            'message' => 'CT',
        ]);

        $response->assertOk()
            ->assertJsonPath('state', 'COLLECTING');
        $this->assertStringContainsString($services[0]->name, $response->json('response'));
        $this->assertStringContainsString('What date would you prefer', $response->json('response'));
    }

    public function test_scan_alias_matches_ultrasound_instead_of_ct(): void
    {
        [, $services] = $this->createContext([
            'CT Scan (Head/Chest/Abdomen)',
            'Ultrasound Scan (Obstetric)',
        ]);

        app(BookingChatService::class)->handle('scan-alias', 'scan', 'web');

        $this->assertSame($services[1]->id, $this->state('scan-alias')['service_id']);
    }

    public function test_service_recognized_from_partial_name(): void
    {
        [$hospital, $services] = $this->createContext([
            'Dental Checkup & Cleaning',
            'General Consultation',
        ]);

        $response = app(BookingChatService::class)->handle('service-partial-name', 'I need dental cleaning', 'web');

        $this->assertSame($services[0]->id, $this->state('service-partial-name')['service_id']);
        $this->assertStringContainsString($services[0]->name, $response->message);
        $this->assertStringContainsString('What date would you prefer?', $response->message);
    }

    public function test_booking_does_not_dump_full_service_list(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('book-no-service-list', 'I want to book an appointment', 'web');

        $this->assertStringContainsString('What service do you need', $response->message);
        $this->assertStringNotContainsString('Available services at', $response->message);
    }

    public function test_booking_keyword_beats_escalation_keyword(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('book-beats-escalation', 'I need a human and I want to book an appointment', 'web');

        $this->assertStringContainsString('What service do you need', $response->message);
        $this->assertStringNotContainsString('best phone number', mb_strtolower($response->message));
    }

    public function test_service_ambiguous_asks_which_one(): void
    {
        $this->createContext(['Dental Checkup & Cleaning', 'Teeth Cleaning']);

        $response = app(BookingChatService::class)->handle('service-ambiguous', 'Book a cleaning', 'web');

        $this->assertNull($this->state('service-ambiguous')['service_id']);
        $this->assertStringContainsString('What service do you need', $response->message);
    }

    public function test_multi_field_message_skips_matching_prompt(): void
    {
        [, $services, $doctors] = $this->createContext([
            'Dental Checkup & Cleaning',
            'Optical Eye Checkup',
        ]);
        DoctorAvailability::query()
            ->where('doctor_id', $doctors[0]->id)
            ->update([
                'start_time' => '08:00',
                'end_time' => '17:00',
                'is_active' => true,
            ]);

        $response = app(BookingChatService::class)->handle(
            'multi-field-skip',
            'Book a dental checkup tomorrow at 10am. My name is Patricia Wanjiku. My phone is 254748249882.',
            'web',
        );

        $this->assertSame($services[0]->id, $this->state('multi-field-skip')['service_id']);
        $this->assertSame(CarbonImmutable::now()->addDay()->toDateString(), $this->state('multi-field-skip')['date']);
        $this->assertSame('10:00', $this->state('multi-field-skip')['time']);
        $this->assertSame('Patricia Wanjiku', $this->state('multi-field-skip')['patient_name']);
        $this->assertSame('254748249882', $this->state('multi-field-skip')['patient_phone']);
        $this->assertSame('CONFIRMING', $response->state);
    }

    public function test_mid_booking_escalation_preserves_state(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);

        $chat->handle('mid-booking-escalation', 'Book Dental Cleaning', 'web');
        $escalation = $chat->handle('mid-booking-escalation', 'I need to speak to someone', 'web');
        $continue = $chat->handle('mid-booking-escalation', 'continue booking', 'web');

        $this->assertStringContainsString('best phone number', mb_strtolower($escalation->message));
        $this->assertSame('COLLECTING', $continue->state);
        $this->assertStringContainsString('What date would you prefer?', $continue->message);
        $this->assertStringNotContainsString('Available services at', $continue->message);
    }

    public function test_continue_booking_after_human_escalation_uses_service_prompt(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);

        $chat->handle('continue-booking-after-escalation', 'I want to book an appointment', 'web');
        $chat->handle('continue-booking-after-escalation', 'I need to speak to someone', 'web');
        $continue = $chat->handle('continue-booking-after-escalation', 'continue booking', 'web');

        $this->assertStringContainsString('What service do you need', $continue->message);
        $this->assertStringNotContainsString('Available services at', $continue->message);
    }

    public function test_cancel_clears_state(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('cancel-booking', 'Book Dental Cleaning', 'web');
        $cancelled = app(BookingChatService::class)->handle('cancel-booking', 'cancel', 'web');

        $this->assertStringContainsString('Your booking has been cancelled', $cancelled->message);
        $this->assertSame('CANCELLED', $cancelled->state);
    }

    public function test_idle_cancel_does_not_cancel_a_nonexistent_booking(): void
    {
        $this->createContext();
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle('idle-cancel', 'cancel', 'web');

        $this->assertSame('IDLE', $response->state);
        $this->assertStringNotContainsString('booking has been cancelled', mb_strtolower($response->message));
    }

    public function test_greeting_from_idle_uses_chatbot_name(): void
    {
        [$hospital] = $this->createContext();
        $hospital->forceFill(['chatbot_name' => 'Pearlie'])->save();

        $response = app(BookingChatService::class)->handle('idle-greeting', 'hello', 'web');

        $this->assertStringContainsString('Pearlie', $response->message);
    }

    public function test_greeting_during_booking_prompts_for_the_next_missing_field(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $chat->handle('active-greeting', 'Book Dental Cleaning', 'web');

        $response = $chat->handle('active-greeting', 'hello', 'web');

        $this->assertSame('COLLECTING', $response->state);
        $this->assertStringContainsString('What date would you prefer?', $response->message);
        $this->assertStringNotContainsString('healthcare assistant', mb_strtolower($response->message));
    }

    public function test_out_of_scope_stays_on_topic(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle('out-of-scope', 'What is the capital of Kenya?', 'web');

        $this->assertStringContainsString('services, appointments, and hospital information', $response->message);
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

    public function test_booking_date_prompt_includes_the_expected_format_and_example(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle(
            'date-format-prompt',
            'Book Dental Cleaning',
            'web',
        );

        $this->assertStringContainsString('DD/MM/YYYY', $response->message);
        $this->assertStringContainsString('15/10/2026', $response->message);
        $this->assertStringContainsString('YYYY-MM-DD', $response->message);
    }

    public function test_booking_accepts_iso_kenyan_and_hyphenated_dates_and_natural_language(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);

        foreach ([
            'iso' => ['2026-10-15', '2026-10-15'],
            'kenyan' => ['15/10/2026', '2026-10-15'],
            'hyphenated' => ['15-10-2026', '2026-10-15'],
            'english-natural-date' => ['October 15, 2026', '2026-10-15'],
            'swahili-natural-date' => ['tarehe 15 Oktoba 2026', '2026-10-15'],
            'next-monday' => ['next Monday', '2026-10-05'],
            'swahili-weekday' => ['Jumatatu', '2026-10-05'],
            'tomorrow' => ['tomorrow', '2026-10-03'],
            'swahili-tomorrow' => ['kesho', '2026-10-03'],
        ] as $case => [$input, $expectedDate]) {
            $sessionId = 'date-format-'.$case;
            $chat->handle($sessionId, 'Book Dental Cleaning', 'web');
            $response = $chat->handle($sessionId, $input, 'web');

            $this->assertSame($expectedDate, $response->collectedFields['date']);
        }
    }

    public function test_ambiguous_date_is_rejected_with_format_guidance(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $chat->handle('date-ambiguous', 'Book Dental Cleaning', 'web');

        $response = $chat->handle('date-ambiguous', '03/04', 'web');

        $this->assertSame('COLLECTING', $response->state);
        $this->assertNull($response->collectedFields['date']);
        $this->assertStringContainsString('Sorry, I didn\'t catch that', $response->message);
        $this->assertStringContainsString('DD/MM/YYYY', $response->message);
    }

    public function test_past_date_is_rejected_with_future_date_guidance(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $chat->handle('date-past', 'Book Dental Cleaning', 'web');

        $response = $chat->handle('date-past', '01/01/2020', 'web');

        $this->assertNull($response->collectedFields['date']);
        $this->assertStringContainsString('date is in the past', $response->message);
        $this->assertStringContainsString('DD/MM/YYYY', $response->message);
    }

    public function test_date_more_than_twelve_months_ahead_is_rejected(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $chat->handle('date-too-far', 'Book Dental Cleaning', 'web');

        $response = $chat->handle('date-too-far', '01/01/2030', 'web');

        $this->assertNull($response->collectedFields['date']);
        $this->assertStringContainsString('too far ahead', $response->message);
        $this->assertStringContainsString('12 months', $response->message);
    }

    public function test_swahili_booking_date_prompt_and_invalid_date_guidance_are_in_swahili(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $prompt = $chat->handle('date-swahili', 'Nataka Dental Cleaning', 'web');
        $response = $chat->handle('date-swahili', '03/04', 'web');

        $this->assertStringContainsString('DD/MM/YYYY', $prompt->message);
        $this->assertStringContainsString('miadi tarehe gani', $prompt->message);
        $this->assertStringContainsString('Samahani', $response->message);
        $this->assertStringContainsString('tafadhali', mb_strtolower($response->message));

        $chat->handle('date-swahili-past', 'Nataka Dental Cleaning', 'web');
        $pastDateResponse = $chat->handle('date-swahili-past', '01/01/2020', 'web');

        $this->assertStringContainsString('imepita', mb_strtolower($pastDateResponse->message));
        $this->assertStringContainsString('DD/MM/YYYY', $pastDateResponse->message);

        $chat->handle('date-swahili-future', 'Nataka Dental Cleaning', 'web');
        $futureDateResponse = $chat->handle('date-swahili-future', '01/01/2030', 'web');

        $this->assertStringContainsString('mbali sana', mb_strtolower($futureDateResponse->message));
        $this->assertStringContainsString('miezi 12', mb_strtolower($futureDateResponse->message));
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
        $this->assertStringNotContainsString('email', mb_strtolower($response->message));
        $this->assertStringNotContainsString('KSh', $response->message);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_confirmation_yes_asks_for_payment_method_before_stk_push(): void
    {
        [$hospital] = $this->createContext(price: 1, mpesaEnabled: true);
        $this->configureMpesaFakes();
        $chat = app(BookingChatService::class);
        $summary = $chat->handle(
            'state-confirm-yes',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );

        $this->assertSame('CONFIRMING', $summary->state);
        $this->assertStringContainsString('M-Pesa amount: KSh 1.00', $summary->message);

        $paymentChoice = $chat->handle('state-confirm-yes', 'yes', 'web');

        $this->assertSame('PAYMENT_CHOICE', $paymentChoice->state);
        $this->assertStringContainsString('pay now via M-Pesa', $paymentChoice->message);
        $this->assertDatabaseCount('appointment_requests', 0);

        $response = $chat->handle('state-confirm-yes', 'Pay now', 'web');
        $this->assertSame('PAYMENT_PENDING', $response->state);
        $this->assertSame(1, AppointmentRequest::query()->where('id', $response->appointmentId)->count());
        $this->assertTrue($response->paymentRequired);
        $this->assertStringNotContainsString('KSh', $response->message);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/mpesa/stkpush/v1/processrequest'));
    }

    public function test_patient_can_pay_at_hospital_without_triggering_mpesa(): void
    {
        [$hospital] = $this->createContext(price: 1, mpesaEnabled: true);
        Http::preventStrayRequests();
        $chat = app(BookingChatService::class);
        $chat->handle(
            'state-pay-at-hospital',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient, phone 254748249882.',
            'web',
        );
        $choice = $chat->handle('state-pay-at-hospital', 'yes', 'web');

        $response = $chat->handle('state-pay-at-hospital', 'Pay at hospital', 'web');
        $appointment = AppointmentRequest::query()->where('session_id', 'state-pay-at-hospital')->firstOrFail();

        $this->assertSame('PAYMENT_CHOICE', $choice->state);
        $this->assertSame('COMPLETED', $response->state);
        $this->assertSame(AppointmentRequest::STATUS_CONFIRMED, $appointment->status);
        $this->assertSame('pending', $appointment->payment_status);
        $this->assertSame('cash', $appointment->payment_method);
        $this->assertSame('at_hospital', $appointment->payment_due_at);
        $this->assertStringContainsString('Payment of KES 1.00 is due at the hospital', $response->message);
        $this->assertDatabaseCount('mpesa_payments', 0);
    }

    public function test_invalid_early_time_offers_available_slots_before_confirmation(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle(
            'alternative-early-time',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 3am. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );

        $this->assertStringContainsString('09:00, 09:30', $response->message);
        $this->assertStringNotContainsString('03:00', $response->message);
        $this->assertSame('COLLECTING', $response->state);
        $this->assertNull($response->collectedFields['time']);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_unavailable_time_offers_alternatives_before_confirmation(): void
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
        $response = $chat->handle(
            'alternative-same-day',
            'Book Dental Cleaning with Dr. Amina Njeri Saturday at 2pm. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );

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
        $response = $chat->handle(
            'alternative-nearest-days',
            'Book Dental Cleaning with Dr. Amina Njeri Saturday at 9am. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );

        $this->assertStringContainsString('Monday October 5', $response->message);
        $this->assertStringContainsString('Tuesday October 6', $response->message);
        $this->assertStringContainsString('Wednesday October 7', $response->message);
        $this->assertStringContainsString('05/10/2026', $response->message);
        $this->assertStringContainsString('06/10/2026', $response->message);
        $this->assertStringContainsString('07/10/2026', $response->message);
        $this->assertSame('COLLECTING', $response->state);
        $this->assertNull($response->collectedFields['date']);
        $this->assertNull($response->collectedFields['time']);
        $this->assertSame($doctors[0]->id, $response->collectedFields['doctor_id']);
        $this->assertSame('Test Patient', $response->collectedFields['patient_name']);
        $this->assertDatabaseCount('appointment_requests', 0);

        $swahiliResponse = $chat->handle(
            'alternative-nearest-days-swahili',
            'Nataka Dental Cleaning with Dr. Amina Njeri Saturday at 9am. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );

        $this->assertStringContainsString('hapatikani', mb_strtolower($swahiliResponse->message));
        $this->assertStringContainsString('05/10/2026', $swahiliResponse->message);
        $this->assertStringContainsString('06/10/2026', $swahiliResponse->message);
        $this->assertStringContainsString('07/10/2026', $swahiliResponse->message);
        $this->assertStringNotContainsString('October', $swahiliResponse->message);
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
        $alternatives = $chat->handle(
            'alternative-booking-success',
            'Book Dental Cleaning with Dr. Amina Njeri Saturday at 2pm. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );
        $confirmation = $chat->handle('alternative-booking-success', '09:30', 'web');

        $chat->handle('alternative-booking-success', 'yes', 'web');
        $response = $chat->handle('alternative-booking-success', 'Pay at hospital', 'web');
        $appointment = AppointmentRequest::query()
            ->where('session_id', 'alternative-booking-success')
            ->firstOrFail();

        $this->assertStringContainsString('09:30, 10:00, 10:30', $alternatives->message);
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
        app(BookingChatService::class)->handle('specialty-fallback', 'yes', 'web');
        $booking = app(BookingChatService::class)->handle('specialty-fallback', 'Pay at hospital', 'web');
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

        $this->assertStringContainsString('What date would you prefer', $response->message);

        $chat = app(BookingChatService::class);
        $chat->handle('specialty-not-required', 'tomorrow', 'web');
        $chat->handle('specialty-not-required', 'My name is Test Patient', 'web');
        $doctorPrompt = $chat->handle('specialty-not-required', '254748249882', 'web');

        $this->assertStringContainsString('Dr. Amina Njeri', $doctorPrompt->message);
        $this->assertStringContainsString('Dr. Brian Otieno', $doctorPrompt->message);
        $this->assertStringContainsString('Which doctor', $doctorPrompt->message);
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
        $chat->handle('state-payment-pending', 'yes', 'web');
        $paymentStarted = $chat->handle('state-payment-pending', 'Pay now', 'web');

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

        $this->assertStringContainsString('What service do you need', $response->message);
        $this->assertSame('COLLECTING', $response->state);
    }

    public function test_chat_matches_a_doctor_from_a_partial_name(): void
    {
        [, , $doctors] = $this->createContext(doctorNames: ['Dr. Amina Njeri', 'Dr. Brian Otieno']);

        $response = app(BookingChatService::class)->handle(
            'partial-doctor-name',
            'Book Dental Cleaning with Dr. Amina',
            'web',
        );

        $this->assertSame($doctors[0]->id, $response->collectedFields['doctor_id']);
    }

    public function test_chat_requests_missing_booking_fields_one_at_a_time(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);

        $service = $chat->handle('fields-one-at-a-time', 'I want to book an appointment', 'web');
        $date = $chat->handle('fields-one-at-a-time', 'Dental Cleaning', 'web');
        $name = $chat->handle('fields-one-at-a-time', 'tomorrow', 'web');
        $phone = $chat->handle('fields-one-at-a-time', 'Test Patient', 'web');
        $time = $chat->handle('fields-one-at-a-time', '254748249882', 'web');
        $confirmation = $chat->handle('fields-one-at-a-time', '9am', 'web');

        $this->assertStringContainsString('What service do you need', $service->message);
        $this->assertStringContainsString('What date', $date->message);
        $this->assertStringContainsString('What time', $time->message);
        $this->assertStringContainsString('full name', $name->message);
        $this->assertStringContainsString('mobile number', $phone->message);
        $this->assertSame('CONFIRMING', $confirmation->state);
        $this->assertStringNotContainsString('email', mb_strtolower($confirmation->message));
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_chat_greeting_uses_the_hospital_chatbot_name_without_ai(): void
    {
        [$hospital] = $this->createContext();
        $hospital->forceFill(['chatbot_name' => 'Pearlie'])->save();
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle('configured-greeting', 'Hello!', 'web');

        $this->assertStringContainsString("I'm Pearlie", $response->message);
        $this->assertStringContainsString('Booking Chat Hospital', $response->message);
        $this->assertSame('IDLE', $response->state);
    }

    public function test_swahili_greeting_and_ambiguous_follow_up_keep_the_session_language(): void
    {
        $this->createContext();
        Http::preventStrayRequests();
        $chat = app(BookingChatService::class);

        $greeting = $chat->handle('swahili-language-session', 'Habari yako', 'web');
        $followUp = $chat->handle('swahili-language-session', 'yes', 'web');

        $this->assertStringContainsString('Habari!', $greeting->message);
        $this->assertSame('sw', $greeting->collectedFields['language']);
        $this->assertStringContainsString('Hakuna miadi', $followUp->message);
        $this->assertSame('sw', $followUp->collectedFields['language']);
        $this->assertSame('sw', $chat->getState('swahili-language-session')['language']);
    }

    public function test_swahili_help_services_location_and_thanks_intents_use_swahili_replies(): void
    {
        [$hospital] = $this->createContext();
        $hospital->forceFill(['address' => '12 Hospital Road'])->save();
        Http::preventStrayRequests();
        $chat = app(BookingChatService::class);

        $help = $chat->handle('swahili-help', 'Nisaidie', 'web');
        $services = $chat->handle('swahili-services', 'Mnatoa huduma gani', 'web');
        $location = $chat->handle('swahili-location', 'Mko wapi', 'web');
        $thanks = $chat->handle('swahili-thanks', 'Asante', 'web');

        $this->assertStringContainsString('Naweza kukusaidia', $help->message);
        $this->assertStringContainsString('Huduma zinazopatikana', $services->message);
        $this->assertStringContainsString('iko katika 12 Hospital Road', $location->message);
        $this->assertStringContainsString('Karibu!', $thanks->message);
        $this->assertSame('sw', $chat->getState('swahili-services')['language']);
    }

    public function test_swahili_booking_intent_uses_swahili_collection_prompts(): void
    {
        $this->createContext();
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'swahili-booking-language',
            'Nataka kuweka miadi',
            'web',
        );

        $this->assertSame('COLLECTING', $response->state);
        $this->assertStringStartsWith('Sawa, naweza kukusaidia kupanga miadi', $response->message);
        $this->assertStringContainsString('Unahitaji huduma gani?', $response->message);
        $this->assertStringNotContainsString('Sikuweza kutambua huduma hiyo.', $response->message);
        $this->assertSame('sw', $response->collectedFields['language']);
    }

    public function test_swahili_chat_cancels_booking_and_releases_its_slot_when_mpesa_credentials_are_missing(): void
    {
        $this->createContext(mpesaEnabled: true);
        config(['integrations.allow_global_fallback' => false]);
        Http::preventStrayRequests();

        $chat = app(BookingChatService::class);
        $sessionId = 'swahili-missing-mpesa-credentials';
        $chat->handle($sessionId, 'Nataka kuweka miadi', 'web');
        $chat->handle($sessionId, 'Dental Cleaning', 'web');
        $chat->handle($sessionId, 'kesho', 'web');
        $chat->handle($sessionId, 'Test Patient', 'web');
        $chat->handle($sessionId, '0748249882', 'web');
        $chat->handle($sessionId, '9 asubuhi', 'web');
        $chat->handle($sessionId, 'ndiyo', 'web');
        $response = $chat->handle($sessionId, 'M-Pesa', 'web');
        $appointment = AppointmentRequest::withoutGlobalScopes()
            ->where('session_id', $sessionId)
            ->first();

        $this->assertSame('CANCELLED', $response->state);
        $this->assertStringContainsString('M-Pesa haijasanidiwa', $response->message);
        $this->assertNotNull($appointment);
        $this->assertSame(AppointmentRequest::STATUS_CANCELLED, $appointment->status);
        $this->assertDatabaseMissing('slot_holds', [
            'appointment_request_id' => $appointment->id,
        ]);
        $this->assertDatabaseMissing('mpesa_payments', [
            'appointment_request_id' => $appointment->id,
        ]);
        Http::assertNothingSent();
    }

    public function test_chat_keeps_general_knowledge_questions_in_hospital_scope(): void
    {
        $this->createContext();
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'out-of-scope-question',
            'What is the capital of France?',
            'web',
        );

        $this->assertStringContainsString('services, appointments, and hospital information', $response->message);
        $this->assertStringNotContainsString('Paris', $response->message);
    }

    public function test_chat_help_keeps_an_in_progress_booking_intact(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $bookingPrompt = $chat->handle('help-preserves-booking', 'Book Dental Cleaning', 'web');
        Http::preventStrayRequests();

        $help = $chat->handle('help-preserves-booking', 'help', 'web');

        $this->assertStringContainsString('appointment bookings', $help->message);
        $this->assertSame('COLLECTING', $help->state);
        $this->assertSame($bookingPrompt->collectedFields['service_id'], $help->collectedFields['service_id']);
    }

    public function test_chat_start_over_clears_a_booking_in_progress(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $chat->handle('start-over-booking', 'Book Dental Cleaning', 'web');

        $response = $chat->handle('start-over-booking', 'start over', 'web');

        $this->assertSame('IDLE', $response->state);
        $this->assertSame('booking_reset', $response->source);
        $this->assertNull($chat->getState('start-over-booking')['service_id']);
    }

    public function test_valid_chat_state_is_persisted_and_returned(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle(
            'valid-chat-state',
            'Book Dental Cleaning',
            'web',
        );

        $this->assertSame('COLLECTING', $response->state);
        $this->assertIsInt($response->collectedFields['service_id']);
        $this->assertDatabaseHas('conversations', [
            'session_id' => 'valid-chat-state',
            'chat_state->state' => 'COLLECTING',
        ]);
    }

    public function test_unknown_chat_state_keys_are_logged_and_reset(): void
    {
        $this->createContext();
        $this->storeChatState('unknown-state-key', [
            'state' => 'COLLECTING',
            'unknown_field' => 'unexpected',
        ]);
        Log::spy();

        $response = app(BookingChatService::class)->handle('unknown-state-key', 'reset', 'web');

        $this->assertSame('IDLE', $response->state);
        $this->assertDatabaseHas('conversations', [
            'session_id' => 'unknown-state-key',
            'user_message' => 'reset',
            'chat_state->state' => 'IDLE',
        ]);
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []): bool => (
                $message === 'Invalid booking chat state was reset.'
                && $context['channel'] === 'web'
                && isset($context['session_hash'])
                && $context['unknown_keys'] === ['unknown_field']
            ));
    }

    public function test_past_chat_state_dates_are_logged_and_reset(): void
    {
        $this->createContext();
        $this->storeChatState('past-chat-state-date', [
            'state' => 'COLLECTING',
            'date' => '2026-10-01',
        ]);
        Log::spy();

        $response = app(BookingChatService::class)->handle('past-chat-state-date', 'reset', 'web');

        $this->assertSame('IDLE', $response->state);
        $this->assertDatabaseHas('conversations', [
            'session_id' => 'past-chat-state-date',
            'user_message' => 'reset',
            'chat_state->state' => 'IDLE',
        ]);
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => $message === 'Invalid booking chat state was reset.');
    }

    public function test_non_integer_chat_state_service_ids_are_logged_and_reset(): void
    {
        $this->createContext();
        $this->storeChatState('invalid-chat-state-service-id', [
            'state' => 'COLLECTING',
            'service_id' => '1',
        ]);
        Log::spy();

        $response = app(BookingChatService::class)->handle('invalid-chat-state-service-id', 'reset', 'web');

        $this->assertSame('IDLE', $response->state);
        $this->assertDatabaseHas('conversations', [
            'session_id' => 'invalid-chat-state-service-id',
            'user_message' => 'reset',
            'chat_state->state' => 'IDLE',
        ]);
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => $message === 'Invalid booking chat state was reset.');
    }

    public function test_thank_you_does_not_become_the_patient_name_during_booking(): void
    {
        $this->createContext();
        $chat = app(BookingChatService::class);
        $chat->handle('thanks-during-booking', 'Book Dental Cleaning', 'web');
        $chat->handle('thanks-during-booking', 'tomorrow at 9am', 'web');
        $chat->handle('thanks-during-booking', '254748249882', 'web');

        $response = $chat->handle('thanks-during-booking', 'Thank you', 'web');
        $state = $chat->getState('thanks-during-booking');

        $this->assertStringContainsString("You're welcome", $response->message);
        $this->assertSame('COLLECTING', $response->state);
        $this->assertNull($state['patient_name']);
        $this->assertSame('254748249882', $state['patient_phone']);
    }

    public function test_services_overview_uses_only_the_hospital_active_catalog(): void
    {
        [$hospital, $services] = $this->createContext(['Dental Cleaning', 'General Consultation']);
        $hospital->services()->create([
            'name' => 'Retired Service',
            'price' => 100,
            'duration_minutes' => 30,
            'category' => 'Other',
            'is_active' => false,
        ]);
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'dynamic-services-overview',
            'What services do you offer?',
            'web',
        );

        $this->assertStringContainsString($services[0]->name, $response->message);
        $this->assertStringContainsString($services[1]->name, $response->message);
        $this->assertStringNotContainsString('Retired Service', $response->message);
        $this->assertSame('hospital_services', $response->source);
    }

    public function test_doctor_availability_question_uses_active_hospital_doctors(): void
    {
        [, , $doctors] = $this->createContext(doctorNames: ['Dr. Amina Njeri', 'Dr. Brian Otieno']);
        $doctors[0]->update(['specialization' => 'Dentist']);
        $doctors[1]->update(['specialization' => 'General Practitioner']);
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'dynamic-doctor-question',
            'Do you have a dentist?',
            'web',
        );

        $this->assertStringContainsString('Dr. Amina Njeri', $response->message);
        $this->assertStringNotContainsString('Dr. Brian Otieno', $response->message);
        $this->assertSame('IDLE', $response->state);
        $this->assertSame('hospital_doctors', $response->source);
    }

    public function test_dentist_question_discloses_listed_general_doctors_without_claiming_they_are_dentists(): void
    {
        [$hospital, , $doctors] = $this->createContext(doctorNames: ['Dr. Pearl Consultant']);
        $doctors[0]->update(['specialization' => 'Consultation']);
        $otherHospital = Hospital::factory()->create(['name' => 'Demo Hospital']);
        app()->instance('currentHospital', $otherHospital);
        User::factory()->for($otherHospital, 'hospital')->create([
            'name' => 'Dr. Demo Dentist',
            'is_doctor' => true,
            'is_active' => true,
            'role' => 'doctor',
            'specialization' => 'Dentist',
        ]);
        app()->instance('currentHospital', $hospital);
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'dentist-question-with-general-provider',
            'Do you have a dentist?',
            'web',
        );

        $this->assertStringContainsString('Dr. Pearl Consultant', $response->message);
        $this->assertStringContainsString('Consultation', $response->message);
        $this->assertStringNotContainsString('Dr. Demo Dentist', $response->message);
        $this->assertStringNotContainsString('doctor in that specialty listed', mb_strtolower($response->message));
        $this->assertSame('hospital_doctors', $response->source);
        $this->assertDatabaseCount('escalations', 0);
    }

    public function test_unqualified_tomorrow_availability_question_returns_only_tenant_doctors_with_open_slots(): void
    {
        [$hospital, , $doctors] = $this->createContext(doctorNames: ['Dr. Pearl Available']);
        $otherHospital = Hospital::factory()->create(['name' => 'Demo Hospital']);
        app()->instance('currentHospital', $otherHospital);
        $demoDoctor = User::factory()->for($otherHospital, 'hospital')->create([
            'name' => 'Dr. Demo Available',
            'is_doctor' => true,
            'is_active' => true,
            'role' => 'doctor',
        ]);
        DoctorAvailability::query()->create([
            'doctor_id' => $demoDoctor->id,
            'day_of_week' => CarbonImmutable::tomorrow()->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
            'is_active' => true,
        ]);
        app()->instance('currentHospital', $hospital);
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'doctor-availability-tomorrow-question',
            'who is available tomorrow',
            'web',
        );

        $this->assertStringContainsString('Dr. Pearl Available', $response->message);
        $this->assertStringContainsString('09:00', $response->message);
        $this->assertStringNotContainsString('Dr. Demo Available', $response->message);
        $this->assertSame('hospital_doctor_availability', $response->source);
        $this->assertDatabaseCount('escalations', 0);
    }

    public function test_swahili_dental_doctor_question_only_returns_dental_specialists(): void
    {
        [, , $doctors] = $this->createContext(doctorNames: ['Dr. Amina Njeri', 'Dr. Brian Otieno']);
        $doctors[0]->update(['specialization' => 'Dentist']);
        $doctors[1]->update(['specialization' => 'General Practitioner']);
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'dynamic-swahili-dentist-question',
            'Mna daktari wa meno?',
            'web',
        );

        $this->assertStringContainsString('Dr. Amina Njeri', $response->message);
        $this->assertStringNotContainsString('Dr. Brian Otieno', $response->message);
        $this->assertSame('IDLE', $response->state);
        $this->assertSame('hospital_doctors', $response->source);
    }

    public function test_hospital_location_answer_uses_current_hospital_settings(): void
    {
        [$hospital] = $this->createContext();
        $hospital->forceFill(['address' => 'Updated clinic address, Nyahururu'])->save();
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'dynamic-hospital-location',
            'Where is the hospital located?',
            'web',
        );

        $this->assertStringContainsString('Updated clinic address, Nyahururu', $response->message);
        $this->assertSame('hospital_information', $response->source);
    }

    public function test_hospital_hours_answer_uses_current_hospital_settings(): void
    {
        [$hospital] = $this->createContext();
        $hospital->forceFill([
            'business_hours' => [
                'monday' => ['open' => '07:30', 'close' => '17:30', 'closed' => false],
                'sunday' => ['open' => '09:00', 'close' => '12:00', 'closed' => true],
            ],
            'hours_emergency' => '24/7',
        ])->save();
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'dynamic-hospital-hours',
            'What are your opening hours?',
            'web',
        );

        $this->assertStringContainsString('Monday: 07:30-17:30', $response->message);
        $this->assertStringContainsString('Sunday: closed', $response->message);
        $this->assertStringContainsString('24/7', $response->message);
    }

    public function test_hospital_contact_answer_uses_current_phone_and_email(): void
    {
        [$hospital] = $this->createContext();
        $hospital->forceFill([
            'phone' => '0700123456',
            'email' => 'updated@example.test',
        ])->save();
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'dynamic-hospital-contact',
            'How can I contact the hospital?',
            'web',
        );

        $this->assertStringContainsString('0700123456', $response->message);
        $this->assertStringContainsString('updated@example.test', $response->message);
    }

    public function test_status_question_without_an_appointment_gives_a_local_answer(): void
    {
        $this->createContext();
        Http::preventStrayRequests();

        $response = app(BookingChatService::class)->handle(
            'missing-appointment-status',
            'What is my booking status?',
            'web',
        );

        $this->assertStringContainsString('could not find an appointment request', $response->message);
        $this->assertSame('appointment_status', $response->source);
    }

    public function test_chat_omits_the_price_when_mpesa_is_disabled(): void
    {
        $this->createContext(price: 1500);
        $chat = app(BookingChatService::class);
        Http::preventStrayRequests();

        $priceQuestion = $chat->handle('chat-price-visibility', 'How much does the service cost?', 'web');
        $confirmation = $chat->handle(
            'chat-price-visibility',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient, phone 254748249882, email patient@test.com.',
            'web',
        );

        $this->assertStringContainsString('contact Booking Chat Hospital', $priceQuestion->message);
        $this->assertStringNotContainsString('KSh', $priceQuestion->message);
        $this->assertSame('CONFIRMING', $confirmation->state);
        $this->assertStringNotContainsString('KSh', $confirmation->message);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    public function test_tenant_chat_does_not_show_service_selector_or_prices(): void
    {
        [$hospital] = $this->createContext(price: 1500);

        $this->withoutVite()
            ->get('/h/'.$hospital->slug.'/chat')
            ->assertDontSee('Choose a service')
            ->assertDontSee('data-price="1500.00"', false)
            ->assertDontSee('Dental Cleaning — KSh 1,500.00 (30 min)', false);
    }

    public function test_multi_message_booking_collects_fields_in_order(): void
    {
        $this->createContext(doctorNames: ['Dr. Amina Njeri', 'Dr. Test Live']);
        $chat = app(BookingChatService::class);
        $datePrompt = $chat->handle('natural-multi', 'Book Dental Cleaning', 'web');
        $namePrompt = $chat->handle('natural-multi', 'tomorrow at 9am', 'web');
        $doctorPrompt = $chat->handle(
            'natural-multi',
            'My name is Test Patient, phone 254748249882, email patient@test.com',
            'web',
        );
        $confirmation = $chat->handle('natural-multi', 'Dr. Amina Njeri', 'web');

        $this->assertStringContainsString('What date would you prefer', $datePrompt->message);
        $this->assertStringContainsString('full name', mb_strtolower($namePrompt->message));
        $this->assertStringContainsString('Which doctor', $doctorPrompt->message);
        $this->assertSame('CONFIRMING', $confirmation->state);
    }

    public function test_typo_tolerant_service_matching_finds_dental_cleaning(): void
    {
        [$hospital, $services] = $this->createContext();

        app(BookingChatService::class)->handle('natural-typo', 'Book dantel cleening', 'web');

        $this->assertSame($services[0]->id, $this->state('natural-typo')['service_id']);
    }

    public function test_confirmation_summary_omits_optional_email(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle(
            'natural-missing-email',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient, phone 254748249882',
            'web',
        );

        $this->assertSame('CONFIRMING', $response->state);
        $this->assertStringContainsString('phone 254748249882', $response->message);
        $this->assertStringNotContainsString('email', mb_strtolower($response->message));
        $this->assertStringNotContainsString('KSh', $response->message);
    }

    public function test_chat_does_not_require_email_for_booking(): void
    {
        $this->createContext(price: 0);
        $chat = app(BookingChatService::class);
        $summary = $chat->handle(
            'booking-without-email',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient, phone 254748249882',
            'web',
        );

        $response = $chat->handle('booking-without-email', 'yes', 'web');
        $appointment = AppointmentRequest::query()->findOrFail($response->appointmentId);

        $this->assertSame('CONFIRMING', $summary->state);
        $this->assertStringContainsString('Dental Cleaning', $summary->message);
        $this->assertStringContainsString('Dr. Amina Njeri', $summary->message);
        $this->assertStringContainsString('phone 254748249882', $summary->message);
        $this->assertStringNotContainsString('email', mb_strtolower($summary->message));
        $this->assertStringNotContainsString('KSh', $summary->message);
        $this->assertSame('COMPLETED', $response->state);
        $this->assertSame(null, $appointment->email);
    }

    public function test_chat_never_asks_for_email(): void
    {
        $this->createContext();

        $response = app(BookingChatService::class)->handle(
            'booking-asks-for-phone-not-email',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient',
            'web',
        );

        $this->assertStringContainsString('mobile number', $response->message);
        $this->assertStringNotContainsString('email', mb_strtolower($response->message));
        $this->assertSame('COLLECTING', $response->state);
    }

    public function test_chat_captures_email_when_provided(): void
    {
        $this->createContext(price: 0);
        $chat = app(BookingChatService::class);
        $summary = $chat->handle(
            'booking-with-email',
            'Book Dental Cleaning with Dr. Amina Njeri tomorrow at 9am. My name is Test Patient, phone 254748249882. My email is patient@test.com.',
            'web',
        );

        $response = $chat->handle('booking-with-email', 'yes', 'web');
        $appointment = AppointmentRequest::query()->findOrFail($response->appointmentId);

        $this->assertSame('CONFIRMING', $summary->state);
        $this->assertSame('patient@test.com', $summary->collectedFields['patient_email']);
        $this->assertStringNotContainsString('patient@test.com', $summary->message);
        $this->assertSame('COMPLETED', $response->state);
        $this->assertSame('patient@test.com', $appointment->email);
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

    public function test_concurrent_messages_wait_for_the_same_session_lock(): void
    {
        [$hospital] = $this->createContext();
        $this->travelBack();
        $sessionId = 'concurrent-booking-session';
        $lockKey = 'booking-chat-state:'.hash('sha256', implode(':', [
            $hospital->id,
            'web',
            $sessionId,
        ]));
        $lock = Cache::lock($lockKey, 120);
        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::lock($lockKey, 120)->get());

        try {
            $exception = null;

            try {
                app(BookingChatService::class)->handle($sessionId, 'Book Dental Cleaning', 'web');
            } catch (LockTimeoutException $lockTimeoutException) {
                $exception = $lockTimeoutException;
            }

            $this->assertInstanceOf(LockTimeoutException::class, $exception);
            $this->assertDatabaseMissing('conversations', ['session_id' => $sessionId]);
        } finally {
            $lock->release();
        }

        $response = app(BookingChatService::class)->handle($sessionId, 'Book Dental Cleaning', 'web');

        $this->assertSame('COLLECTING', $response->state);
        $this->assertNotNull($response->collectedFields['service_id']);
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
     * @return array{Hospital, array<int, Service>, User, BookingChatService, string, ChatResponse, ChatResponse, ChatResponse, ChatResponse}
     */
    private function advanceToSwahiliBookingTimeStep(): array
    {
        [$hospital, $services, $doctors] = $this->createContext();
        Http::preventStrayRequests();
        $chat = app(BookingChatService::class);
        $sessionId = 'swahili-booking-natural-time';

        $chat->handle($sessionId, 'Nataka kuweka miadi', 'web');
        $datePrompt = $chat->handle($sessionId, 'Dental Cleaning', 'web');
        $namePrompt = $chat->handle($sessionId, 'kesho', 'web');
        $phonePrompt = $chat->handle($sessionId, 'Jina langu ni Juma', 'web');
        $timePrompt = $chat->handle($sessionId, '0712345678', 'web');

        return [
            $hospital,
            $services,
            $doctors[0],
            $chat,
            $sessionId,
            $datePrompt,
            $namePrompt,
            $phonePrompt,
            $timePrompt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $channelId, string $channel = 'web'): array
    {
        return app(BookingChatService::class)->getState($channelId, $channel);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function storeChatState(string $sessionId, array $state): void
    {
        Conversation::query()->create([
            'session_id' => $sessionId,
            'user_message' => 'stored state',
            'ai_response' => 'stored response',
            'confidence_score' => 1,
            'channel' => 'web',
            'escalated' => false,
            'chat_state' => $state,
            'chat_state_updated_at' => now(),
        ]);
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
