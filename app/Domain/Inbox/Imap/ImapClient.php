<?php

namespace App\Domain\Inbox\Imap;

/**
 * A minimal IMAP4rev1 client: log in, select a folder, find new messages by UID and fetch them
 * whole (without marking them read). Enough to collect a dealer's mail; nothing is deleted or
 * moved on the server.
 */
class ImapClient
{
    private int $tag = 0;

    private bool $open = false;

    public function __construct(private readonly ImapTransport $transport) {}

    public function connect(string $host, int $port, string $encryption, string $username, string $password, int $timeout = 20): void
    {
        $this->transport->open($host, $port, $encryption, $timeout);
        $this->open = true;
        $greeting = $this->transport->readLine();

        if (! str_starts_with($greeting, '* OK') && ! str_starts_with($greeting, '* PREAUTH')) {
            throw new ImapException(__('The mail server did not greet as expected: :line', ['line' => trim($greeting)]));
        }

        if ($encryption === 'tls') {
            $this->command('STARTTLS');
            $this->transport->enableTls();
        }

        [$status, , $text] = $this->command('LOGIN '.self::quote($username).' '.self::quote($password), throw: false);

        if ($status !== 'OK') {
            throw new ImapException(__('The mail server refused the login: :reason', ['reason' => $text]));
        }
    }

    /**
     * @return array{uidvalidity: int|null, exists: int}
     */
    public function select(string $folder): array
    {
        [, $lines] = $this->command('EXAMINE '.self::quote($folder)); // read-only: flags stay as they are
        $result = ['uidvalidity' => null, 'exists' => 0];

        foreach ($lines as [$line]) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m) === 1) {
                $result['uidvalidity'] = (int) $m[1];
            }

            if (preg_match('/^\* (\d+) EXISTS/i', $line, $m) === 1) {
                $result['exists'] = (int) $m[1];
            }
        }

        return $result;
    }

    /**
     * UIDs above the given one, ascending.
     *
     * @return list<int>
     */
    public function uidsAfter(int $uid): array
    {
        [, $lines] = $this->command('UID SEARCH UID '.($uid + 1).':*');
        $uids = [];

        foreach ($lines as [$line]) {
            if (preg_match('/^\* SEARCH ?(.*)$/i', trim($line), $m) === 1) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $value) {
                    if (ctype_digit($value) && (int) $value > $uid) { // "n:*" also returns the last UID when nothing is newer
                        $uids[] = (int) $value;
                    }
                }
            }
        }

        sort($uids);

        return array_values(array_unique($uids));
    }

    /**
     * The whole message (RFC 822) without setting \Seen.
     */
    public function fetch(int $uid): ?string
    {
        [, $lines] = $this->command("UID FETCH {$uid} (UID BODY.PEEK[])");

        foreach ($lines as [$line, $literal]) {
            if ($literal !== null && preg_match('/FETCH \(/i', $line) === 1) {
                return $literal;
            }
        }

        return null;
    }

    public function logout(): void
    {
        if (! $this->open) {
            return;
        }

        try {
            $this->command('LOGOUT', throw: false);
        } catch (ImapException) {
            // the server may close first
        } finally {
            $this->transport->close();
            $this->open = false;
        }
    }

    /**
     * Sends a command and reads up to its tagged answer. Literals ({n} at the end of a line)
     * are read as bytes and returned with their line.
     *
     * @return array{0: string, 1: list<array{0: string, 1: string|null}>, 2: string}
     */
    private function command(string $command, bool $throw = true): array
    {
        $tag = sprintf('A%03d', ++$this->tag);
        $this->transport->write("{$tag} {$command}\r\n");
        $lines = [];

        while (true) {
            $line = $this->transport->readLine();
            $literal = null;

            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m) === 1) {
                $literal = $this->transport->read((int) $m[1]);
                $rest = $this->transport->readLine(); // the closing ")" of the FETCH
                $line = rtrim($line)."\r\n";
                $lines[] = [$line, $literal];

                if (str_starts_with($rest, $tag.' ')) {
                    $line = $rest;
                } else {
                    continue;
                }
            }

            if (str_starts_with($line, $tag.' ')) {
                $parts = explode(' ', trim($line), 3);
                $status = strtoupper($parts[1] ?? 'BAD');
                $text = $parts[2] ?? '';

                if ($throw && $status !== 'OK') {
                    throw new ImapException(__('The mail server answered ":command" with: :reason', ['command' => strtok($command, ' '), 'reason' => trim($status.' '.$text)]));
                }

                return [$status, $lines, $text];
            }

            if ($literal === null) {
                $lines[] = [$line, null];
            }
        }
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
