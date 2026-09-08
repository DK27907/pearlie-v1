<?php

namespace App\Jobs;

use App\Mail\EscalationNotification;
use App\Models\Escalation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendEscalationNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public Escalation $escalation;
    public array $meta;

    public int $tries = 3;
    public int $backoff = 60; // seconds

    public function __construct(Escalation $escalation, array $meta = [])
    {
        $this->escalation = $escalation;
        $this->meta = $meta;
    }

    public function handle()
    {
        $to = config('pearlie.escalation_email');
        if ($to) {
            Mail::to($to)->send(new EscalationNotification($this->escalation, $this->meta));
        }

        // Also send to other notify_emails
        $more = config('pearlie.notify_emails', []);
        foreach ($more as $addr) {
            $addr = trim($addr);
            if (empty($addr) || $addr === $to) continue;
            Mail::to($addr)->send(new EscalationNotification($this->escalation, $this->meta));
        }
    }
}
