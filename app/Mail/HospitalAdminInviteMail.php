<?php

namespace App\Mail;

use App\Models\Hospital;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HospitalAdminInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Hospital $hospital,
        public readonly string $inviteUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set up your '.$this->hospital->name.' MediDesk account');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.hospital-admin-invite');
    }
}
