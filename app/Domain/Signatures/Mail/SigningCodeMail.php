<?php

namespace App\Domain\Signatures\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SigningCodeMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $code, public readonly string $dealerName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your signing code: :code', ['code' => $this->code]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.signing.code');
    }
}
