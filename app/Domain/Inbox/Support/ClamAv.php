<?php

namespace App\Domain\Inbox\Support;

/**
 * Optional virus scan with clamd (INSTREAM). Not configured: nothing to say (null).
 * Configured but unreachable: the attachment is refused, never stored unscanned.
 */
class ClamAv
{
    public function scan(string $content): ?string
    {
        $address = config('dealer.mail.clamav');

        if (! is_string($address) || $address === '') {
            return null;
        }

        $socket = @stream_socket_client($address, $errno, $error, 10);

        if ($socket === false) {
            return __('The virus scanner cannot be reached.');
        }

        try {
            stream_set_timeout($socket, 30);
            fwrite($socket, "zINSTREAM\0");

            foreach (str_split($content, 8192) as $chunk) {
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }

            fwrite($socket, pack('N', 0));
            $answer = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($socket);
        }

        if (str_ends_with($answer, 'OK')) {
            return null;
        }

        if (preg_match('/:\s*(.+)\s+FOUND$/', $answer, $m) === 1) {
            return __('Virus found: :name', ['name' => $m[1]]);
        }

        return __('The virus scanner could not check the file.');
    }
}
