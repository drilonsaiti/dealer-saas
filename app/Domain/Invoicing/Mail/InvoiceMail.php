<?php

namespace App\Domain\Invoicing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvoiceMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $recipientName,
        public readonly string $dealerName,
        public readonly ?string $amount,
        public readonly ?string $dueOn,
        public readonly string $pdf,
        public readonly string $fileName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title.' – '.$this->dealerName);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invoices.invoice');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf, $this->fileName)->withMime('application/pdf')];
    }
}
