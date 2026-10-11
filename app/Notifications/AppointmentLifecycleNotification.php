<?php

namespace App\Notifications;

use App\Models\AppointmentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly AppointmentRequest $appointment,
        private readonly string $action,
        private readonly string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Appointment '.$this->action)
            ->greeting('Appointment update')
            ->line(sprintf(
                'Appointment #%d was %s.',
                $this->appointment->id,
                $this->action,
            ))
            ->line(sprintf(
                'Scheduled for %s at %s.',
                $this->appointment->preferred_date?->format('d/m/Y') ?? 'a new date',
                $this->appointment->slot_start_time
                    ? substr((string) $this->appointment->slot_start_time, 0, 5)
                    : 'a new time',
            ));

        if (filled($this->reason)) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'appointment_id' => $this->appointment->id,
            'hospital_id' => $this->appointment->hospital_id,
            'action' => $this->action,
            'reason' => $this->reason,
            'preferred_date' => $this->appointment->preferred_date?->toDateString(),
            'slot_start_time' => $this->appointment->slot_start_time,
        ];
    }
}
