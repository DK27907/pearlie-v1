<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyDoctorSummary extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Collection $appointments,
        private readonly string $date,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $formattedDate = CarbonImmutable::parse($this->date)->toFormattedDateString();
        $mail = (new MailMessage)
            ->subject("Your Patients Today — {$formattedDate}")
            ->greeting("Good morning, {$notifiable->name}.")
            ->line("You have {$this->appointments->count()} patient(s) scheduled for today, {$formattedDate}.");

        if ($this->appointments->isEmpty()) {
            return $mail->line('There are no appointments scheduled for today.');
        }

        foreach ($this->appointments as $appointment) {
            $time = $appointment->slot_start_time
                ? substr($appointment->slot_start_time, 0, 5)
                : 'Time not set';
            $mail->line(sprintf(
                '%s — %s at %s%s',
                $time,
                $appointment->name ?: 'Patient name not provided',
                $appointment->phone ?: 'Phone not provided',
                $appointment->reason ? " ({$appointment->reason})" : '',
            ));
        }

        return $mail;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'doctor_id' => $notifiable->id,
            'date' => $this->date,
            'count' => $this->appointments->count(),
        ];
    }
}
