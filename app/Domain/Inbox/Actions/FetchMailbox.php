<?php

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Imap\ImapClient;
use App\Domain\Inbox\Imap\ImapException;
use App\Domain\Inbox\Models\Mailbox;
use Throwable;

/**
 * Collects the new messages of a mailbox (UID above the last one seen; read-only on the
 * server, nothing is deleted). Progress is kept per message, so an interrupted run goes on
 * where it stopped. Returns the number of new messages, or the error.
 */
class FetchMailbox
{
    public function __construct(private readonly StoreIncomingEmail $store) {}

    /**
     * @return array{new: int, error: string|null}
     */
    public function __invoke(Mailbox $mailbox): array
    {
        $client = app(ImapClient::class);
        $new = 0;

        try {
            $client->connect($mailbox->imap_host, $mailbox->imap_port, $mailbox->imap_encryption, $mailbox->imap_username, (string) $mailbox->secret('imap_password'));
            $folder = $client->select($mailbox->imap_folder);

            if ($folder['uidvalidity'] !== null && $mailbox->uid_validity !== $folder['uidvalidity']) {
                // The server renumbered the folder: read it again (Message-IDs prevent duplicates).
                $mailbox->forceFill(['uid_validity' => $folder['uidvalidity'], 'last_uid' => 0])->save();
            }

            foreach (array_slice($client->uidsAfter($mailbox->last_uid), 0, (int) config('dealer.mail.fetch_limit', 50)) as $uid) {
                $raw = $client->fetch($uid);

                if ($raw !== null && ($this->store)($mailbox, $raw, $uid) !== null) {
                    $new++;
                }

                $mailbox->forceFill(['last_uid' => $uid])->save();
            }

            $mailbox->forceFill(['last_fetched_at' => now(), 'last_error' => null])->save();

            return ['new' => $new, 'error' => null];
        } catch (ImapException $e) {
            $mailbox->forceFill(['last_error' => $e->getMessage()])->save();

            return ['new' => $new, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);
            $mailbox->forceFill(['last_error' => $e->getMessage()])->save();

            return ['new' => $new, 'error' => $e->getMessage()];
        } finally {
            $client->logout();
        }
    }

    /**
     * "Test connection": log in and open the folder.
     */
    public function test(Mailbox $mailbox): ?string
    {
        $client = app(ImapClient::class);

        try {
            $client->connect($mailbox->imap_host, $mailbox->imap_port, $mailbox->imap_encryption, $mailbox->imap_username, (string) $mailbox->secret('imap_password'));
            $client->select($mailbox->imap_folder);

            return null;
        } catch (ImapException $e) {
            return $e->getMessage();
        } finally {
            $client->logout();
        }
    }
}
