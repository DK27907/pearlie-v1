<?php

namespace App\Notifications;

use App\Models\Escalation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EscalationPatientMessage extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Escalation $escalation,
        private readonly string $message,
        private readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'escalation_id' => $this->escalation->id,
            'title' => 'Patient replied to an escalation',
            'message' => $this->message,
            'url' => $this->url,
        ];
    }
}
