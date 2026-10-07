<?php

namespace App\Notifications;

use App\Models\Escalation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EscalationCreated extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Escalation $escalation,
        private readonly string $url,
        private readonly ?float $confidence,
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
            'title' => 'Patient needs assistance',
            'user_name' => $this->escalation->patient_name,
            'user_phone' => $this->escalation->patient_phone,
            'question' => $this->escalation->user_message,
            'confidence_score' => $this->confidence,
            'status' => $this->escalation->status,
            'url' => $this->url,
        ];
    }
}
