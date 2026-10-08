<?php

namespace App\Domain\Signatures\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Copy of the signed document for the signer.
 */
class SignedDocumentMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $signerName,
        public readonly string $documentTitle,
        public readonly string $dealerName,
        public readonly string $pdf,
        public readonly string $fileName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Signed: :document', ['document' => $this->documentTitle]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.signing.signed');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf, $this->fileName)->withMime('application/pdf')];
    }
}
