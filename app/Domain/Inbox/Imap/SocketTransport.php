<?php

namespace App\Domain\Inbox\Imap;

/**
 * IMAP over a plain PHP stream (no imap extension needed): implicit TLS on 993, or STARTTLS.
 * Certificates are verified.
 */
class SocketTransport implements ImapTransport
{
    /** @var resource|null */
    private $stream = null;

    public function open(string $host, int $port, string $encryption, int $timeout): void
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
        $stream = @stream_socket_client("{$scheme}://{$host}:{$port}", $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            throw new ImapException(__('The mail server :host cannot be reached: :reason', ['host' => $host, 'reason' => $error ?: (string) $errno]));
        }

        stream_set_timeout($stream, $timeout);
        $this->stream = $stream;
    }

    public function enableTls(): void
    {
        if (@stream_socket_enable_crypto($this->stream(), true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
            throw new ImapException(__('The secure connection (STARTTLS) to the mail server failed.'));
        }
    }

    public function write(string $data): void
    {
        if (@fwrite($this->stream(), $data) === false) {
            throw new ImapException(__('The connection to the mail server was interrupted.'));
        }
    }

    public function readLine(): string
    {
        $line = fgets($this->stream());

        if ($line === false) {
            throw new ImapException(__('The connection to the mail server was interrupted.'));
        }

        return $line;
    }

    public function read(int $bytes): string
    {
        $data = '';

        while (strlen($data) < $bytes) {
            $chunk = fread($this->stream(), $bytes - strlen($data));

            if ($chunk === false || ($chunk === '' && feof($this->stream()))) {
                throw new ImapException(__('The connection to the mail server was interrupted.'));
            }

            $data .= $chunk;
        }

        return $data;
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    /**
     * @return resource
     */
    private function stream()
    {
        return $this->stream ?? throw new ImapException(__('The connection to the mail server was interrupted.'));
    }
}
