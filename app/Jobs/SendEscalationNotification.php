<?php

namespace App\Jobs;

use App\Mail\EscalationNotification;
use App\Models\Escalation;
use App\Models\Hospital;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
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

    public function handle()
    {
        $hospital = Hospital::query()->find($this->hospitalId);
        if (! $hospital) {
            throw new \RuntimeException('The escalation hospital no longer exists.');
        }
        app()->instance('currentHospital', $hospital);
        $this->escalation->unsetRelations();

        $recipients = $this->recipient
            ? [$this->recipient]
            : array_unique(array_filter(array_merge(
                [(string) config('pearlie.escalation_email')],
                config('pearlie.notify_emails', []),
            )));

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send(new EscalationNotification($this->escalation, $this->meta));
            } catch (Throwable $exception) {
                Log::error('Unable to send escalation email.', [
                    'escalation_id' => $this->escalation->id,
                    'recipient' => $recipient,
                    'exception' => $exception,
                ]);

                throw $exception;
            }
        }
    }
}
