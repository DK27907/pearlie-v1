<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Services\BookingChatService;
use Database\Seeders\DuskSeedSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProbeBookingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_out_of_hours_and_multi_field_booking_messages_preserve_expected_state(): void
    {
        (new DuskSeedSeeder)->run();
        $hospital = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        app()->instance('currentHospital', $hospital);

        $chat = app(BookingChatService::class);

        $outOfHours = $chat->handle('probe-out-of-hours', 'Book a dental checkup tomorrow at 3am.', 'web');

        $this->assertSame('COLLECTING', $outOfHours->state);
        $this->assertStringContainsString('doesn\'t have availability at 3:00 AM', $outOfHours->message);
        $this->assertNull($chat->getState('probe-out-of-hours')['time']);

        $multiField = $chat->handle(
            'probe-multi-field',
            'Book a dental checkup tomorrow at 10am. My name is Patricia Wanjiku. My phone is 254748249882.',
            'web',
        );
        $multiFieldState = $chat->getState('probe-multi-field');

        $this->assertSame('CONFIRMING', $multiField->state);
        $this->assertStringContainsString('Dental Checkup & Cleaning', $multiField->message);
        $this->assertSame('Patricia Wanjiku', $multiFieldState['patient_name']);
        $this->assertSame('254748249882', $multiFieldState['patient_phone']);
        $this->assertSame('10:00', $multiFieldState['time']);

        $servicePrompt = $chat->handle('probe-service-prompt', 'I want to book an appointment', 'web');

        $this->assertSame('COLLECTING', $servicePrompt->state);
        $this->assertStringContainsString('What service do you need?', $servicePrompt->message);
    }
}
