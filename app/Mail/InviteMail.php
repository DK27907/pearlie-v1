<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $token;

    public string $registrationUrl;

    public string $hospitalName;

    /**
     * Create a new message instance.
     */
    public function __construct(string $token, ?string $hospitalSlug = null)
    {
        $this->token = $token;
        $this->registrationUrl = route('register', array_filter(['hospital' => $hospitalSlug]));
        $this->hospitalName = (string) pearlie_config('hospital.name');
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        return $this->subject('You are invited to MediDesk AI')
            ->view('emails.invite')
            ->with([
                'token' => $this->token,
                'registrationUrl' => $this->registrationUrl,
                'hospitalName' => $this->hospitalName,
            ]);
    }
}
