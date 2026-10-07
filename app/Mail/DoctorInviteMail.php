<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class DoctorInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $doctor,
        public readonly string $token,
        public readonly Carbon $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're invited to the MediDesk AI Doctor Dashboard",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.doctor-invite',
            with: [
                'setupUrl' => route('doctor.setup', [
                    'token' => $this->token,
                    'hospital' => $this->doctor->hospital?->slug,
                ]),
            ],
        );
    }
}
