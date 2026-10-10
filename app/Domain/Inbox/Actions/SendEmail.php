<?php

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Enums\EmailStatus;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Inbox\Support\MailboxTransports;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends a draft through the mailbox's own SMTP server, as a reply in the same thread
 * (In-Reply-To / References). The original is marked handled.
 */
class SendEmail
{
    public function __construct(private readonly MailboxTransports $transports) {}

    public function __invoke(EmailMessage $draft): EmailMessage
    {
        if (! in_array($draft->status, [EmailStatus::Draft, EmailStatus::Failed], true) || $draft->direction !== EmailMessage::OUT) {
            throw new BusinessRuleException(__('Only drafts can be sent.'));
        }

        $mailbox = $draft->mailbox;

        if (! $mailbox->canSend()) {
            throw new BusinessRuleException(__('Enter the SMTP server of the mailbox first.'));
        }

        $domain = Str::after($mailbox->email, '@') ?: 'localhost';
        $messageId = Str::uuid()->toString().'@'.$domain;

        $email = (new Email)
            ->from(new Address($mailbox->email, $mailbox->name))
            ->to(...array_map(fn (array $a): Address => new Address($a['email'], (string) ($a['name'] ?? '')), $draft->to ?? []))
            ->subject((string) $draft->subject)
            ->text((string) $draft->body_text);

        foreach ($draft->cc ?? [] as $cc) {
            $email->addCc(new Address($cc['email'], (string) ($cc['name'] ?? '')));
        }

        $email->getHeaders()->addIdHeader('Message-ID', $messageId);

        if ($draft->in_reply_to !== null) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $draft->in_reply_to);
            $email->getHeaders()->addIdHeader('References', $draft->in_reply_to);
        }

        foreach ($draft->attachments() as $document) {
            $version = $document->currentVersion;

            if ($version !== null) {
                $email->attach((string) Storage::disk($version->disk)->get($version->path), $version->original_name, $version->mime);
            }
        }

        try {
            $this->transports->for($mailbox)->send($email);
        } catch (TransportExceptionInterface $e) {
            $draft->forceFill(['status' => EmailStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            throw new BusinessRuleException(__('The e-mail could not be sent: :reason', ['reason' => $e->getMessage()]));
        }

        $draft->forceFill([
            'status' => EmailStatus::Sent,
            'message_id' => $messageId,
            'from_address' => $mailbox->email,
            'from_name' => $mailbox->name,
            'sent_at' => now(),
            'error' => null,
        ])->save();

        $draft->replyTo?->forceFill(['handled_at' => now(), 'handled_by' => auth()->id()])->save();

        return $draft;
    }
}
