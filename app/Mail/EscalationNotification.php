<?php

namespace App\Mail;

use App\Models\Escalation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class EscalationNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Escalation $escalation;
    public array $meta;

    public function __construct(Escalation $escalation, array $meta = [])
    {
        $this->escalation = $escalation;
        $this->meta = $meta;
    }

    public function build()
    {
        $subject = sprintf('New Pearlie Escalation #%d', $this->escalation->id);

        return $this->subject($subject)
            ->view('emails.escalation_notification')
            ->with([
                'escalation' => $this->escalation,
                'meta' => $this->meta,
            ]);
    }
}
