<?php

namespace App\Domain\Listings\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the dealer about a new enquiry from the website (plain values, so the queued mail
 * needs no database access).
 */
class EnquiryReceivedMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly string $text,
        public readonly ?string $vehicle,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('New enquiry from :name', ['name' => $this->name]).($this->vehicle ? ' – '.$this->vehicle : ''));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.listings.enquiry');
    }
}
