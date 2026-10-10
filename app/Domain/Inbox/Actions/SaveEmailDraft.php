<?php

namespace App\Domain\Inbox\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentLink;
use App\Domain\Inbox\Enums\EmailStatus;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Inbox\Models\Mailbox;
use App\Support\BusinessRuleException;

/**
 * A reply is first a draft (saved, visible to colleagues); it leaves only with "Send".
 * The original text is quoted below; documents of the vehicle file can be attached.
 */
class SaveEmailDraft
{
    /**
     * @param  array{body: string, subject?: string|null, to?: string|null, cc?: string|null, document_ids?: list<string>}  $data
     */
    public function reply(EmailMessage $original, array $data): EmailMessage
    {
        $draft = new EmailMessage([
            'mailbox_id' => $original->mailbox_id,
            'direction' => EmailMessage::OUT,
            'status' => EmailStatus::Draft,
            'in_reply_to' => $original->message_id,
            'reply_to_message_id' => $original->getKey(),
        ]);
        $draft->forceFill(['party_id' => $original->party_id, 'stock_cycle_id' => $original->stock_cycle_id]);

        $subject = (string) $original->subject;
        $data['subject'] ??= preg_match('/^\s*(re|aw|ri|tr)\s*:/i', $subject) === 1 ? $subject : 'Re: '.$subject;
        $data['to'] ??= $original->from_address;

        return $this->save($draft, $data);
    }

    /**
     * A new e-mail prepared by the system (e.g. a warranty registration): a draft in the inbox,
     * with documents the system chose, sent only when a person clicks "Send".
     *
     * @param  array{body: string, subject: string, to: string, cc?: string|null}  $data
     * @param  list<string>  $documentIds
     */
    public function compose(Mailbox $mailbox, array $data, ?string $stockCycleId = null, ?string $partyId = null, array $documentIds = []): EmailMessage
    {
        $draft = new EmailMessage(['mailbox_id' => $mailbox->getKey(), 'direction' => EmailMessage::OUT, 'status' => EmailStatus::Draft]);
        $draft->forceFill(['stock_cycle_id' => $stockCycleId, 'party_id' => $partyId]);
        $this->save($draft, $data);

        foreach (array_unique($documentIds) as $id) {
            DocumentLink::query()->firstOrCreate(['document_id' => $id, 'linkable_type' => $draft->getMorphClass(), 'linkable_id' => $draft->getKey()]);
        }

        return $draft;
    }

    /**
     * The mailbox the system writes from: the first active one that can send.
     */
    public static function sendingMailbox(): ?Mailbox
    {
        return Mailbox::query()->active()->whereNotNull('smtp_host')->whereNotNull('smtp_port')->orderBy('created_at')->first();
    }

    /**
     * @param  array{body: string, subject?: string|null, to?: string|null, cc?: string|null, document_ids?: list<string>}  $data
     */
    public function save(EmailMessage $draft, array $data): EmailMessage
    {
        if ($draft->exists && $draft->status !== EmailStatus::Draft && $draft->status !== EmailStatus::Failed) {
            throw new BusinessRuleException(__('Only drafts can be changed.'));
        }

        $to = self::addresses((string) ($data['to'] ?? ''));

        if ($to === []) {
            throw new BusinessRuleException(__('Enter at least one valid recipient.'));
        }

        $draft->fill([
            'to' => $to,
            'cc' => self::addresses((string) ($data['cc'] ?? '')),
            'subject' => mb_substr(trim((string) ($data['subject'] ?? '')), 0, 500),
            'body_text' => trim($data['body']),
        ]);
        $draft->forceFill(['status' => EmailStatus::Draft, 'error' => null])->save();

        if (array_key_exists('document_ids', $data)) {
            $this->attach($draft, $data['document_ids']);
        }

        return $draft;
    }

    /**
     * Only documents of the same vehicle file (or already on the message) can be attached.
     *
     * @param  list<string>  $documentIds
     */
    private function attach(EmailMessage $draft, array $documentIds): void
    {
        $allowed = $draft->stock_cycle_id !== null
            ? Document::query()->whereHas('links', fn ($q) => $q->where('linkable_type', 'stock_cycle')->where('linkable_id', $draft->stock_cycle_id))->pluck('id')->all()
            : [];

        $morph = $draft->getMorphClass();

        DocumentLink::query()->where('linkable_type', $morph)->where('linkable_id', $draft->getKey())
            ->whereNotIn('document_id', $documentIds)->delete();

        foreach (array_intersect($documentIds, $allowed) as $id) {
            DocumentLink::query()->firstOrCreate(['document_id' => $id, 'linkable_type' => $morph, 'linkable_id' => $draft->getKey()]);
        }
    }

    /**
     * "a@b.ch, Name <c@d.ch>" → [{email, name}]
     *
     * @return list<array{email: string, name: string|null}>
     */
    public static function addresses(string $value): array
    {
        $result = [];

        foreach (preg_split('/[,;]/', $value) ?: [] as $part) {
            $part = trim($part);
            $name = null;

            if (preg_match('/^(.*)<([^>]+)>$/', $part, $m) === 1) {
                $name = trim($m[1], " \"'") ?: null;
                $part = trim($m[2]);
            }

            if (filter_var($part, FILTER_VALIDATE_EMAIL) !== false) {
                $result[] = ['email' => mb_strtolower($part), 'name' => $name];
            }
        }

        return $result;
    }
}
