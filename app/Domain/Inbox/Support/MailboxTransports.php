<?php

namespace App\Domain\Inbox\Support;

use App\Domain\Inbox\Models\Mailbox;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The SMTP connection of a mailbox (replies go out from the dealer's own server, so they land
 * in its "sent" folder rules and pass SPF/DKIM). Replaced by a recorder in tests.
 */
class MailboxTransports
{
    public function for(Mailbox $mailbox): TransportInterface
    {
        $transport = new EsmtpTransport((string) $mailbox->smtp_host, (int) $mailbox->smtp_port, $mailbox->smtp_encryption === 'ssl');

        if ($mailbox->smtp_encryption === 'none') {
            $transport->setAutoTls(false);
        } elseif ($mailbox->smtp_encryption === 'tls') {
            $transport->setRequireTls(true);
        }

        $username = $mailbox->smtp_username ?: $mailbox->imap_username;
        $password = $mailbox->secret('smtp_password') ?? $mailbox->secret('imap_password');

        if (filled($username) && $password !== null) {
            $transport->setUsername($username);
            $transport->setPassword($password);
        }

        return $transport;
    }
}
