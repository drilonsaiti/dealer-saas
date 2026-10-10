<?php

namespace App\Domain\Inbox\Imap;

/**
 * The byte stream to an IMAP server. The real one is a TLS socket; tests plug in a fake server.
 */
interface ImapTransport
{
    /** @param  string  $encryption  ssl (implicit TLS), tls (STARTTLS later) or none */
    public function open(string $host, int $port, string $encryption, int $timeout): void;

    public function enableTls(): void;

    public function write(string $data): void;

    /** One line including its CRLF. */
    public function readLine(): string;

    public function read(int $bytes): string;

    public function close(): void;
}
