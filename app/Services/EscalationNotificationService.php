<?php

namespace App\Services;

use App\Jobs\SendEscalationNotification;
use App\Models\Escalation;
use App\Models\User;
use App\Notifications\EscalationCreated;
use Illuminate\Support\Facades\Log;
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
            $useGlobalDefaults = ! $tenant;
            $smsPhone = data_get($settings, 'escalation.notify_phone')
                ?: ($useGlobalDefaults ? config('pearlie.escalation.notify_phone') : null)
                ?: $tenant?->phone;
            if ($smsPhone) {
                try {
                    Log::info('Attempting escalation SMS notification.', [
                        'escalation_id' => $escalation->id,
                        'channel' => 'sms',
                    ]);
                    if ($this->notifications->sendSms((string) $smsPhone, $message)) {
                        Log::info('Escalation SMS notification was accepted.', [
                            'escalation_id' => $escalation->id,
                            'channel' => 'sms',
                        ]);
                    } else {
                        Log::warning('Escalation SMS was not accepted by a configured provider.', [
                            'escalation_id' => $escalation->id,
                            'channel' => 'sms',
                        ]);
                    }
                } catch (Throwable $exception) {
                    Log::error('Unable to send escalation SMS.', [
                        'escalation_id' => $escalation->id,
                        'channel' => 'sms',
                        'exception' => $exception,
                    ]);
                }
            } else {
                Log::warning('Escalation SMS recipient is not configured.', [
                    'escalation_id' => $escalation->id,
                    'channel' => 'sms',
                ]);
            }

            $whatsAppPhone = data_get($settings, 'escalation.notify_whatsapp')
                ?: ($useGlobalDefaults ? config('pearlie.escalation.notify_whatsapp') : null)
                ?: ($tenant?->whatsapp_number ?: $smsPhone);
            if ($whatsAppPhone) {
                try {
                    Log::info('Attempting escalation WhatsApp notification.', [
                        'escalation_id' => $escalation->id,
                        'channel' => 'whatsapp',
                    ]);
                    if ($this->whatsApp->sendMessage((string) $whatsAppPhone, $message)['success']) {
                        Log::info('Escalation WhatsApp notification was accepted.', [
                            'escalation_id' => $escalation->id,
                            'channel' => 'whatsapp',
                        ]);
                    } else {
                        Log::warning('Escalation WhatsApp message was not accepted.', [
                            'escalation_id' => $escalation->id,
                            'channel' => 'whatsapp',
                        ]);
                    }
                } catch (Throwable $exception) {
                    Log::error('Unable to send escalation WhatsApp message.', [
                        'escalation_id' => $escalation->id,
                        'channel' => 'whatsapp',
                        'exception' => $exception,
                    ]);
                }
            } else {
                Log::warning('Escalation WhatsApp recipient is not configured.', [
                    'escalation_id' => $escalation->id,
                    'channel' => 'whatsapp',
                ]);
            }

            $workers = User::query()
                ->where(fn ($query) => $query->where('is_admin', true)->orWhere('is_doctor', true))
                ->get();

            if ($workers->isEmpty()) {
                Log::warning('Escalation in-app notification has no eligible health workers.', [
                    'escalation_id' => $escalation->id,
                    'channel' => 'in_app',
                ]);
            }

            foreach ($workers as $worker) {
                try {
                    $url = $worker->isAdmin()
                        ? $adminUrl
                        : route('doctor.escalations.show', $escalation->id);
                    $worker->notify(new EscalationCreated($escalation, $url, $confidence));
                    Log::info('Escalation in-app notification was created.', [
                        'escalation_id' => $escalation->id,
                        'channel' => 'in_app',
                        'worker_id' => $worker->id,
                    ]);
                } catch (Throwable $exception) {
                    Log::error('Unable to create an in-app escalation notification.', [
                        'escalation_id' => $escalation->id,
                        'channel' => 'in_app',
                        'worker_id' => $worker->id,
                        'exception' => $exception,
                    ]);
                }
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

            if ($emails === []) {
                Log::warning('Escalation email recipient is not configured.', [
                    'escalation_id' => $escalation->id,
                    'channel' => 'email',
                ]);
            }

            foreach ($emails as $email) {
                try {
                    Log::info('Attempting to queue escalation email notification.', [
                        'escalation_id' => $escalation->id,
                        'channel' => 'email',
                    ]);
                    SendEscalationNotification::dispatch($escalation, $metadata, $email)
                        ->onQueue('emails');
                    Log::info('Escalation email notification was queued.', [
                        'escalation_id' => $escalation->id,
                        'channel' => 'email',
                    ]);
                } catch (Throwable $exception) {
                    Log::error('Unable to dispatch escalation email.', [
                        'escalation_id' => $escalation->id,
                        'channel' => 'email',
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
