<?php

namespace Tests\Fakes;

use App\Domain\Inbox\Imap\ImapException;
use App\Domain\Inbox\Imap\ImapTransport;

/**
 * An in-memory IMAP server speaking just enough of the protocol for ImapClient: greeting,
 * STARTTLS, LOGIN, EXAMINE, UID SEARCH, UID FETCH (literal), LOGOUT.
 */
class FakeImapServer implements ImapTransport
{
    /** @var array<int, string> uid => raw message */
    public array $messages = [];

    public int $uidValidity = 1001;

    public bool $refuseConnection = false;

    /** @var list<string> */
    public array $commands = [];

    private string $buffer = '';

    public function __construct(public string $username = 'info@aziri.ch', public string $password = 'secret') {}

    public function add(string $raw, ?int $uid = null): int
    {
        $uid ??= ($this->messages === [] ? 100 : max(array_keys($this->messages)) + 1);
        $this->messages[$uid] = $raw;

        return $uid;
    }

    public function open(string $host, int $port, string $encryption, int $timeout): void
    {
        if ($this->refuseConnection) {
            throw new ImapException("The mail server {$host} cannot be reached: Connection refused");
        }

        $this->buffer = "* OK [CAPABILITY IMAP4rev1 STARTTLS] Fake ready\r\n";
    }

    public function enableTls(): void {}

    public function write(string $data): void
    {
        $line = rtrim($data, "\r\n");
        [$tag, $command] = explode(' ', $line, 2) + [1 => ''];
        $this->commands[] = preg_replace('/^LOGIN .*/', 'LOGIN ***', $command) ?? $command;
        $upper = strtoupper($command);

        $this->buffer .= match (true) {
            $upper === 'STARTTLS' => "{$tag} OK Begin TLS\r\n",
            str_starts_with($upper, 'LOGIN ') => $this->login($tag, substr($command, 6)),
            str_starts_with($upper, 'EXAMINE ') => "* FLAGS (\\Seen)\r\n* ".count($this->messages)." EXISTS\r\n* OK [UIDVALIDITY {$this->uidValidity}] UIDs valid\r\n{$tag} OK [READ-ONLY] EXAMINE completed\r\n",
            str_starts_with($upper, 'UID SEARCH UID ') => $this->search($tag, substr($command, 15)),
            str_starts_with($upper, 'UID FETCH ') => $this->fetch($tag, (int) substr($command, 10)),
            $upper === 'LOGOUT' => "* BYE\r\n{$tag} OK LOGOUT completed\r\n",
            default => "{$tag} BAD unknown\r\n",
        };
    }

    public function readLine(): string
    {
        $position = strpos($this->buffer, "\n");

        if ($position === false) {
            throw new ImapException('The connection to the mail server was interrupted.');
        }

        $line = substr($this->buffer, 0, $position + 1);
        $this->buffer = substr($this->buffer, $position + 1);

        return $line;
    }

    public function read(int $bytes): string
    {
        $data = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);

        return $data;
    }

    public function close(): void
    {
        $this->buffer = '';
    }

    private function login(string $tag, string $arguments): string
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $arguments, $m);
        [$user, $pass] = array_map('stripslashes', $m[1]) + [null, null];

        return $user === $this->username && $pass === $this->password
            ? "{$tag} OK LOGIN completed\r\n"
            : "{$tag} NO [AUTHENTICATIONFAILED] Invalid credentials\r\n";
    }

    private function search(string $tag, string $range): string
    {
        $from = (int) strtok($range, ':');
        $uids = array_filter(array_keys($this->messages), fn (int $uid): bool => $uid >= $from);

        if ($uids === [] && $this->messages !== []) {
            $uids = [max(array_keys($this->messages))]; // like real servers: n:* includes the highest UID
        }

        sort($uids);

        return '* SEARCH '.implode(' ', $uids)."\r\n{$tag} OK SEARCH completed\r\n";
    }

    private function fetch(string $tag, int $uid): string
    {
        if (! isset($this->messages[$uid])) {
            return "{$tag} OK FETCH completed\r\n";
        }

        $raw = $this->messages[$uid];
        $sequence = array_search($uid, array_keys($this->messages), true) + 1;

        return "* {$sequence} FETCH (UID {$uid} BODY[] {".strlen($raw)."}\r\n{$raw})\r\n{$tag} OK FETCH completed\r\n";
    }
}
