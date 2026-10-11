<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MarketingDemoRequest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{name: string, email: string, organization: string, phone?: ?string, message?: ?string}  $request
     */
    public function __construct(public array $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New MediDesk AI demo request',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.marketing-demo-request',
        );
    }
}
