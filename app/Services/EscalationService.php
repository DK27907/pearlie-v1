<?php

namespace App\Services;

use App\Models\Escalation;
use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Mail\EscalationNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

class EscalationService
{
    public function createEscalation(string $sessionId, string $userMessage, ?string $aiResponse = null): Escalation
    {
        $esc = Escalation::create([
            'session_id' => $sessionId,
            'user_message' => $userMessage,
            'ai_response' => $aiResponse,
            'status' => 'pending',
        ]);

        // Notify health workers via email, SMS, and optional WhatsApp
        $this->notifyHealthWorkers($esc);

        return $esc;
    }

    protected function notifyHealthWorkers(Escalation $esc): void
    {
        try {
            Log::info('Escalation created', ['id' => $esc->id, 'session_id' => $esc->session_id]);

            // Try to enrich escalation with any appointment/user info and last confidence
            $appt = AppointmentRequest::where('session_id', $esc->session_id)->latest()->first();
            $conv = Conversation::where('session_id', $esc->session_id)->latest()->first();

            $userName = $appt->name ?? null;
            $userPhone = $appt->phone ?? null;
            $confidence = $conv->confidence_score ?? null;

            $meta = [
                'user_name' => $userName,
                'user_phone' => $userPhone,
                'user_question' => $esc->user_message,
                'confidence_score' => $confidence,
                'timestamp' => $esc->created_at?->toDateTimeString(),
            ];

            // 1) Email notification — primary target is config('pearlie.escalation_email')
            $to = config('pearlie.escalation_email');
            if ($to) {
                try {
                    // Dispatch a job to send the notification with retries/backoff
                    \App\Jobs\SendEscalationNotification::dispatch($esc, $meta);
                    Log::info('Escalation notification job dispatched', ['to' => $to, 'escalation_id' => $esc->id]);
                } catch (\Throwable $e) {
                    Log::error('Failed to dispatch escalation notification job to ' . $to . ': ' . $e->getMessage());
                }
            } else {
                Log::warning('No escalation_email configured in config/pearlie.php');
            }

            // Also dispatch to any notify_emails list for backward compatibility
            $more = config('pearlie.notify_emails', []);
            foreach ($more as $addr) {
                $addr = trim($addr);
                if (empty($addr) || $addr === $to) continue;
                try {
                    \App\Jobs\SendEscalationNotification::dispatch($esc, $meta)->onQueue('emails');
                    Log::info('Escalation notification job dispatched', ['to' => $addr, 'escalation_id' => $esc->id]);
                } catch (\Throwable $e) {
                    Log::error('Failed to dispatch escalation notification job to ' . $addr . ': ' . $e->getMessage());
                }
            }

            // 2) SMS notification via NotificationService
            $smsTo = config('pearlie.escalation_phone') ?: config('pearlie.hospital.phone');
            $smsMessage = sprintf("New escalation: %s from %s. Call %s.",
                mb_strlen($esc->user_message) > 160 ? mb_substr($esc->user_message, 0, 157) . '...' : $esc->user_message,
                $userName ?: 'Patient',
                $userPhone ?: $smsTo
            );

            try {
                app(\App\Services\NotificationService::class)->sendSms($smsTo, $smsMessage);
            } catch (\Throwable $e) {
                Log::error('SMS send failed via NotificationService: ' . $e->getMessage());
            }

            // 3) Optional WhatsApp via NotificationService
            try {
                app(\App\Services\NotificationService::class)->sendWhatsApp($smsTo, $smsMessage);
            } catch (\Throwable $e) {
                Log::error('WhatsApp send failed via NotificationService: ' . $e->getMessage());
            }

        } catch (\Throwable $e) {
            Log::error('Failed to notify health workers for escalation: ' . $e->getMessage());
        }
    }

    /**
     * Public helper to resend notifications for an existing escalation.
     */
    public function resendEscalationNotification(Escalation $esc): void
    {
        // Reuse the same notify path
        $this->notifyHealthWorkers($esc);
        Log::info('Resent notifications for escalation ' . $esc->id);
    }
}
