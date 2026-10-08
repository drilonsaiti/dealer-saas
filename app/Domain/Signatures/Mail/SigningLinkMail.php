<?php

namespace App\Domain\Signatures\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * "Please sign": link to the signing page, in the customer's language.
 */
class SigningLinkMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $signerName,
        public readonly string $documentTitle,
        public readonly string $dealerName,
        public readonly string $url,
        public readonly ?Carbon $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':document to sign – :dealer', ['document' => $this->documentTitle, 'dealer' => $this->dealerName]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.signing.link');
    }
}
