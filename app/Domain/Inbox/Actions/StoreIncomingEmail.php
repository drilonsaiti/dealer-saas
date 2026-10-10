<?php

namespace App\Domain\Inbox\Actions;

use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Documents\Support\DuplicateDocument;
use App\Domain\Inbox\Enums\EmailStatus;
use App\Domain\Inbox\Mime\Attachment;
use App\Domain\Inbox\Mime\MimeParser;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Inbox\Models\Mailbox;
use App\Domain\Inbox\Support\AttachmentGuard;
use App\Domain\Inbox\Support\MessageMatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Stores one fetched e-mail: parsed, matched to contact and vehicle file, attachments into the
 * archive (category "correspondence") or quarantined. The same message is stored only once.
 */
class StoreIncomingEmail
{
    private const MAX_TEXT = 200_000;

    private const MAX_HTML = 1_000_000;

    public function __construct(
        private readonly MimeParser $parser,
        private readonly MessageMatcher $matcher,
        private readonly AttachmentGuard $guard,
        private readonly StoreDocument $storeDocument,
    ) {}

    public function __invoke(Mailbox $mailbox, string $raw, ?int $uid = null): ?EmailMessage
    {
        $parsed = $this->parser->parse($raw);
        $messageId = $parsed->messageId ?? 'sha256-'.hash('sha256', $raw).'@'.$mailbox->getKey();

        if (EmailMessage::query()->where('mailbox_id', $mailbox->getKey())->where('direction', EmailMessage::IN)->where('message_id', $messageId)->exists()) {
            return null;
        }

        $text = mb_substr((string) $parsed->text, 0, self::MAX_TEXT);
        $match = $this->matcher->match($parsed->from['email'] ?? null, (string) $parsed->subject, $text);

        try {
            $message = DB::transaction(function () use ($mailbox, $parsed, $messageId, $uid, $text, $match): EmailMessage {
                $message = new EmailMessage([
                    'mailbox_id' => $mailbox->getKey(),
                    'direction' => EmailMessage::IN,
                    'status' => EmailStatus::Received,
                    'message_id' => $messageId,
                    'in_reply_to' => $parsed->inReplyTo,
                    'uid' => $uid,
                    'from_address' => $parsed->from['email'] ?? null,
                    'from_name' => $parsed->from['name'] ?? null,
                    'to' => $parsed->to,
                    'cc' => $parsed->cc,
                    'subject' => $parsed->subject !== null ? mb_substr($parsed->subject, 0, 500) : null,
                    'body_text' => $text,
                    'body_html' => $parsed->html !== null ? mb_substr($parsed->html, 0, self::MAX_HTML) : null,
                    'sent_at' => $parsed->date ?? now(),
                ]);

                // A reply to one of our e-mails belongs where the original belongs.
                $original = $parsed->inReplyTo !== null
                    ? EmailMessage::query()->where('message_id', $parsed->inReplyTo)->first()
                    : null;

                $message->forceFill([
                    'party_id' => $match['party']?->getKey() ?? $original?->party_id,
                    'stock_cycle_id' => $match['cycle']?->getKey() ?? $original?->stock_cycle_id,
                    'matched_by' => $match['cycle'] !== null || $match['party'] !== null ? $match['by'] : ($original !== null ? 'thread' : null),
                ])->save();

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // fetched by a parallel run
        }

        $this->attachments($message, $parsed->attachments);

        return $message;
    }

    /**
     * @param  list<Attachment>  $attachments
     */
    private function attachments(EmailMessage $message, array $attachments): void
    {
        $quarantined = [];
        $category = null;

        foreach ($attachments as $attachment) {
            // Logos and icons in signatures are not documents.
            if ($attachment->inline && str_starts_with($attachment->contentType, 'image/') && strlen($attachment->content) < 50_000) {
                continue;
            }

            $reason = $this->guard->refuse($attachment);

            if ($reason !== null) {
                $quarantined[] = ['name' => $attachment->filename, 'reason' => $reason];

                continue;
            }

            $category ??= $this->category();
            $links = $message->stockCycle !== null ? [$message, $message->stockCycle] : [$message];
            $path = (string) tempnam(sys_get_temp_dir(), 'mail');
            file_put_contents($path, $attachment->content);

            try {
                ($this->storeDocument)($path, $category, [
                    'title' => pathinfo($attachment->filename, PATHINFO_FILENAME) ?: $attachment->filename,
                    'original_name' => $attachment->filename,
                    'document_on' => $message->sent_at?->toDateString(),
                    'source' => DocumentSource::Email,
                ], $links);
            } catch (DuplicateDocument $e) {
                // Sent again (e.g. the same contract): link the stored one instead of a copy.
                if ($e->existing !== null) {
                    foreach ($links as $record) {
                        $this->link($e->existing->getKey(), $record);
                    }
                }
            } finally {
                @unlink($path);
            }
        }

        if ($quarantined !== []) {
            $message->forceFill(['quarantined' => $quarantined])->save();
        }
    }

    private function link(string $documentId, Model $record): void
    {
        DocumentLink::query()->firstOrCreate([
            'document_id' => $documentId,
            'linkable_type' => $record->getMorphClass(),
            'linkable_id' => $record->getKey(),
        ]);
    }

    private function category(): DocumentCategory
    {
        $category = DocumentCategory::query()->where('key', 'correspondence')->first();

        if ($category === null) {
            app(InstallDefaultDocumentCategories::class)();
            $category = DocumentCategory::query()->where('key', 'correspondence')->firstOrFail();
        }

        return $category;
    }
}
