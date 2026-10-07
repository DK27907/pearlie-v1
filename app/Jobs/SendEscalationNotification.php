<?php

namespace App\Jobs;

use App\Mail\EscalationNotification;
use App\Models\Escalation;
use App\Models\Hospital;
use App\Services\HospitalMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendEscalationNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Escalation $escalation;

    public array $meta;

    public int $hospitalId;

    public int $tries = 3;

    public int $backoff = 60; // seconds

    public function __construct(Escalation $escalation, array $meta = [], ?string $recipient = null)
    {
        $this->escalation = $escalation;
        $this->meta = $meta;
        $this->recipient = $recipient;
        $this->hospitalId = (int) $escalation->hospital_id;
    }

    public ?string $recipient;

    public function handle(): void
    {
        $hospital = Hospital::withoutGlobalScopes()->find($this->hospitalId);
        if (! $hospital) {
            throw new \RuntimeException('The escalation hospital no longer exists.');
        }
        app()->instance('currentHospital', $hospital);
        $this->escalation->unsetRelations();
        $mail = app(HospitalMailService::class);

        $recipients = $this->recipient
            ? [$this->recipient]
            : array_unique(array_filter(array_merge(
                [(string) config('pearlie.escalation_email')],
                config('pearlie.notify_emails', []),
            )));

        if ($recipients === []) {
            Log::warning('Escalation email notification has no configured recipients.', [
                'escalation_id' => $this->escalation->id,
                'channel' => 'email',
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            try {
                Log::info('Attempting escalation email delivery.', [
                    'escalation_id' => $this->escalation->id,
                    'channel' => 'email',
                ]);
                $mail->send($recipient, new EscalationNotification($this->escalation, $this->meta));
                Log::info('Escalation email was handed to the mail transport.', [
                    'escalation_id' => $this->escalation->id,
                    'channel' => 'email',
                ]);
            } catch (Throwable $exception) {
                Log::error('Unable to send escalation email.', [
                    'escalation_id' => $this->escalation->id,
                    'channel' => 'email',
                    'exception' => $exception,
                ]);

                throw $exception;
            }
        }
    }
}
