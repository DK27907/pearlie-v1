<?php

namespace Tests\Feature;

use App\Jobs\SendEscalationNotification;
use App\Mail\EscalationNotification;
use App\Models\Escalation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendEscalationNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_escalation_notification_job_sends_email()
    {
        Mail::fake();

        $esc = Escalation::create([
            'session_id' => 'test-sess-job',
            'user_message' => 'Test job message',
            'ai_response' => 'AI answer',
            'status' => 'pending',
        ]);

        $meta = [
            'user_name' => 'Jane',
            'user_phone' => '+254700000002',
            'user_question' => 'Need help',
            'confidence_score' => 0.4,
            'timestamp' => now()->toDateTimeString(),
        ];

        // Ensure escalation recipient is configured
        Config::set('pearlie.escalation_email', 'frontdesk@example.com');

        // Run the job handler directly so Mail::fake() can capture the send
        $job = new SendEscalationNotification($esc, $meta);
        $job->handle();

        Mail::assertQueued(EscalationNotification::class, function ($mail) use ($esc) {
            return $mail->escalation->id === $esc->id;
        });
    }
}
