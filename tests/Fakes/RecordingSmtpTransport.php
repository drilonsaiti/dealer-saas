<?php

namespace Tests\Fakes;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Collects what would have been sent through a mailbox's SMTP server.
 */
class RecordingSmtpTransport implements TransportInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public ?string $fail = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->fail !== null) {
            throw new TransportException($this->fail);
        }

        assert($message instanceof Email);
        $this->sent[] = $message;

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'recording://';
    }
}
