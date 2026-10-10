<?php

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Models\Mailbox;
use Illuminate\Support\Arr;

/**
 * Creates or edits a mailbox. Passwords left empty keep the stored ones.
 */
class SaveMailbox
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(?Mailbox $mailbox, array $data): Mailbox
    {
        $mailbox ??= new Mailbox;
        $secrets = $mailbox->secrets ?? [];

        foreach (['imap_password', 'smtp_password'] as $key) {
            if (filled($data[$key] ?? null)) {
                $secrets[$key] = (string) $data[$key];
            }
        }

        $mailbox->fill(Arr::except($data, ['imap_password', 'smtp_password']));
        $mailbox->email = mb_strtolower(trim($mailbox->email));
        $mailbox->secrets = $secrets;
        $mailbox->save();

        return $mailbox;
    }
}
