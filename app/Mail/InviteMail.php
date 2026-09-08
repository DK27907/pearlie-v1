<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $token;

    /**
     * Create a new message instance.
     */
    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        return $this->subject('You are invited to Pearlie Admin')
            ->view('emails.invite')
            ->with(['token' => $this->token]);
    }
}
