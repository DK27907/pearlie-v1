<?php

namespace App\Notifications;

use App\Models\AppointmentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NoShowNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly AppointmentRequest $appointment) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You missed your appointment at '.pearlie_config('hospital.name'))
            ->greeting('Hello '.($this->appointment->name ?: 'there').',')
            ->line('We noticed you were unable to attend your appointment at '.pearlie_config('hospital.name').'.')
            ->line('The appointment deposit is retained for a missed appointment.')
            ->line('Please contact us to arrange another appointment.')
            ->line('Call '.pearlie_config('hospital.appointment_phone').' for assistance.');
    }
}
