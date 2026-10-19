<?php

namespace App\Mail;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;

class HospitalAdminWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private readonly string $encryptedInitialPassword;

    public function __construct(
        public readonly Hospital $hospital,
        public readonly User $admin,
        string $initialPassword,
    ) {
        $this->encryptedInitialPassword = Crypt::encryptString($initialPassword);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.$this->hospital->name.' admin account is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hospital-admin-welcome',
            with: [
                'hospital' => $this->hospital,
                'admin' => $this->admin,
                'initialPassword' => Crypt::decryptString($this->encryptedInitialPassword),
                'loginUrl' => route('tenant.login', ['hospital' => $this->hospital->slug]),
            ],
        );
    }
}
