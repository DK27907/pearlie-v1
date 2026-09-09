<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Services\PearlieServiceV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PearlieServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_request_is_recorded_with_a_clear_confirmation(): void
    {
        $result = $this->app->make(PearlieServiceV2::class)->processMessage(
            'I want to book an appointment. My name is Jane Doe, my phone is 0712345678, tomorrow, for a consultation.',
            'test-session',
        );

        $this->assertSame('appointment', $result['source']);
        $this->assertNotNull($result['appointment_id']);
        $this->assertStringContainsString('recorded as pending', $result['response']);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $result['appointment_id'],
            'status' => AppointmentRequest::STATUS_PENDING,
            'phone' => '0712345678',
        ]);
    }
}
