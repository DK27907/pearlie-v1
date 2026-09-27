<?php

namespace App\Services;

use App\Jobs\SendEscalationNotification;
use App\Models\Escalation;
use App\Models\User;
use App\Notifications\EscalationCreated;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class EscalationNotificationService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly WhatsAppService $whatsApp,
    ) {}

    public function notifyHealthWorkers(Escalation $escalation): void
    {
        try {
            $escalation->loadMissing(['latestAppointment', 'latestScoredConversation']);
            $confidence = $escalation->confidence_score;
            $adminUrl = route('admin.escalations.show', $escalation->id);
            $question = mb_substr($escalation->user_message, 0, 100);
            $confidenceText = $confidence === null ? 'unknown' : number_format($confidence, 2);
            $message = sprintf(
                '🚨 ESCALATION #%d: %s (%s) needs help. Question: %s. Confidence: %s. Open: %s',
                $escalation->id,
                $escalation->patient_name,
                $escalation->patient_phone ?: 'phone unavailable',
                $question,
                $confidenceText,
                $adminUrl,
            );

            $tenant = hospital();
            $settings = $tenant?->settings ?? [];
            $useGlobalDefaults = ! $tenant || $tenant->slug === 'pearl';
            $smsPhone = data_get($settings, 'escalation.notify_phone')
                ?: ($useGlobalDefaults ? config('pearlie.escalation.notify_phone') : null)
                ?: $tenant?->phone;
            if ($smsPhone) {
                try {
                    if (! $this->notifications->sendSms((string) $smsPhone, $message)) {
                        Log::warning('Escalation SMS was not accepted by a configured provider.', [
                            'escalation_id' => $escalation->id,
                        ]);
                    }
                } catch (Throwable $exception) {
                    Log::error('Unable to send escalation SMS.', [
                        'escalation_id' => $escalation->id,
                        'exception' => $exception,
                    ]);
                }
            } else {
                Log::warning('Escalation SMS recipient is not configured.', [
                    'escalation_id' => $escalation->id,
                ]);
            }

            $whatsAppPhone = data_get($settings, 'escalation.notify_whatsapp')
                ?: ($useGlobalDefaults ? config('pearlie.escalation.notify_whatsapp') : null)
                ?: ($tenant?->whatsapp_number ?: $smsPhone);
            if ($whatsAppPhone) {
                try {
                    if (! $this->whatsApp->sendMessage((string) $whatsAppPhone, $message)['success']) {
                        Log::warning('Escalation WhatsApp message was not accepted.', [
                            'escalation_id' => $escalation->id,
                        ]);
                    }
                } catch (Throwable $exception) {
                    Log::error('Unable to send escalation WhatsApp message.', [
                        'escalation_id' => $escalation->id,
                        'exception' => $exception,
                    ]);
                }
            } else {
                Log::warning('Escalation WhatsApp recipient is not configured.', [
                    'escalation_id' => $escalation->id,
                ]);
            }

            $workers = User::query()
                ->where(fn ($query) => $query->where('is_admin', true)->orWhere('is_doctor', true))
                ->get();

            try {
                foreach ($workers as $worker) {
                    $url = $worker->isAdmin()
                        ? $adminUrl
                        : route('doctor.escalations.show', $escalation->id);
                    $worker->notify(new EscalationCreated($escalation, $url, $confidence));
                }
            } catch (Throwable $exception) {
                Log::error('Unable to create in-app escalation notifications.', [
                    'escalation_id' => $escalation->id,
                    'exception' => $exception,
                ]);
            }

            $metadata = [
                'user_name' => $escalation->patient_name,
                'user_phone' => $escalation->patient_phone,
                'user_question' => $escalation->user_message,
                'confidence_score' => $confidence,
                'timestamp' => $escalation->created_at?->toDateTimeString(),
                'url' => $adminUrl,
            ];
            $defaultEmails = $useGlobalDefaults
                ? array_merge(
                    explode(',', (string) config('pearlie.escalation_email', '')),
                    (array) config('pearlie.notify_emails', []),
                )
                : [];
            $emails = array_unique(array_filter(array_map('trim', array_merge(
                (array) data_get($settings, 'escalation.emails', []),
                filled(data_get($settings, 'escalation.email'))
                    ? [(string) data_get($settings, 'escalation.email')]
                    : [],
                $defaultEmails,
                filled($tenant?->email) ? [$tenant->email] : [],
            ))));

            foreach ($emails as $email) {
                try {
                    SendEscalationNotification::dispatch($escalation, $metadata, $email)
                        ->onQueue('emails');
                } catch (Throwable $exception) {
                    Log::error('Unable to dispatch escalation email.', [
                        'escalation_id' => $escalation->id,
                        'email' => $email,
                        'exception' => $exception,
                    ]);
                }
            }
        } catch (Throwable $exception) {
            Log::error('Unable to prepare escalation notifications.', [
                'escalation_id' => $escalation->id,
                'exception' => $exception,
            ]);
        }
    }
}
