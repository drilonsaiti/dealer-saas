<?php

namespace App\Domain\Inbox\Mime;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads an RFC 822 / MIME message: encoded headers (RFC 2047), multipart bodies, base64 and
 * quoted-printable, character sets, file names (also RFC 2231). Robust rather than complete:
 * whatever cannot be read is skipped, never fatal.
 */
class MimeParser
{
    private const MAX_DEPTH = 10;

    public function parse(string $raw): ParsedMessage
    {
        [$headers, $body] = self::split($raw);
        $message = new ParsedMessage(
            messageId: self::messageId(self::header($headers, 'message-id')),
            inReplyTo: self::messageId(self::header($headers, 'in-reply-to')),
            from: self::addresses(self::header($headers, 'from'))[0] ?? null,
            to: self::addresses(self::header($headers, 'to')),
            cc: self::addresses(self::header($headers, 'cc')),
            subject: ($subject = self::header($headers, 'subject')) !== null ? self::decodeHeader($subject) : null,
            date: self::date(self::header($headers, 'date')),
        );

        $this->part($headers, $body, $message, 0);

        if ($message->text === null && $message->html !== null) {
            $message->text = self::htmlToText($message->html);
        }

        return $message;
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function part(array $headers, string $body, ParsedMessage $message, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        [$type, $params] = self::parameters(self::header($headers, 'content-type') ?? 'text/plain; charset=us-ascii');
        $type = strtolower($type);
        [$disposition, $dispositionParams] = self::parameters(self::header($headers, 'content-disposition') ?? '');
        $disposition = strtolower($disposition);

        if (str_starts_with($type, 'multipart/') && isset($params['boundary'])) {
            foreach (self::multipart($body, $params['boundary']) as $partRaw) {
                [$partHeaders, $partBody] = self::split($partRaw);
                $this->part($partHeaders, $partBody, $message, $depth + 1);
            }

            return;
        }

        $content = self::decodeBody($body, strtolower(trim(self::header($headers, 'content-transfer-encoding') ?? '7bit')));
        $filename = $dispositionParams['filename'] ?? $params['name'] ?? null;
        $filename = $filename !== null ? self::cleanFilename(self::decodeHeader($filename)) : null;

        if ($type === 'message/rfc822') {
            $message->attachments[] = new Attachment($filename ?? 'message.eml', 'message/rfc822', $content);

            return;
        }

        $isAttachment = $disposition === 'attachment' || ($filename !== null && ! in_array($type, ['text/plain', 'text/html'], true));

        if (! $isAttachment && $type === 'text/plain' && $message->text === null) {
            $message->text = self::toUtf8($content, $params['charset'] ?? null);

            return;
        }

        if (! $isAttachment && $type === 'text/html' && $message->html === null) {
            $message->html = self::toUtf8($content, $params['charset'] ?? null);

            return;
        }

        if ($content === '') {
            return;
        }

        $message->attachments[] = new Attachment(
            $filename ?? ('attachment-'.(count($message->attachments) + 1).self::extensionFor($type)),
            $type,
            $content,
            inline: $disposition === 'inline' || ($disposition === '' && self::header($headers, 'content-id') !== null),
        );
    }

    /**
     * @return array{0: array<string, list<string>>, 1: string}
     */
    private static function split(string $raw): array
    {
        $raw = ltrim($raw, "\r\n");
        $position = strpos($raw, "\r\n\r\n");
        $separator = 4;

        $lf = strpos($raw, "\n\n");

        if ($position === false || ($lf !== false && $lf < $position)) {
            $position = $lf;
            $separator = 2;
        }

        $head = $position === false ? $raw : substr($raw, 0, $position);
        $body = $position === false ? '' : substr($raw, $position + $separator);
        $headers = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $head) ?: [] as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $last = array_key_last($headers[$current]);
                $headers[$current][$last] .= ' '.ltrim($line);

                continue;
            }

            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $current = strtolower(trim($name));
                $headers[$current][] = trim($value);
            }
        }

        return [$headers, $body];
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        return $headers[$name][0] ?? null;
    }

    /**
     * "type/sub; a=b; c="d"" → [type/sub, [a => b, c => d]], with RFC 2231 (name*=, name*0*=).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function parameters(string $value): array
    {
        $parts = self::splitOutsideQuotes($value, ';');
        $main = trim((string) array_shift($parts));
        $params = [];
        $extended = [];

        foreach ($parts as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }

            [$key, $val] = explode('=', $part, 2);
            $key = strtolower(trim($key));
            $val = trim($val);

            if (strlen($val) >= 2 && $val[0] === '"' && str_ends_with($val, '"')) {
                $val = stripcslashes(substr($val, 1, -1));
            }

            if (preg_match('/^([^*]+)\*(\d+)?(\*)?$/', $key, $m) === 1) {
                $extended[$m[1]][(int) ($m[2] ?? 0)] = [$val, isset($m[3]) || ! isset($m[2])];

                continue;
            }

            $params[$key] = $val;
        }

        foreach ($extended as $key => $pieces) {
            ksort($pieces);
            $charset = null;
            $joined = '';

            foreach ($pieces as $index => [$piece, $encoded]) {
                if ($encoded && $index === array_key_first($pieces) && preg_match("/^([^']*)'[^']*'(.*)$/", $piece, $m) === 1) {
                    $charset = $m[1] !== '' ? $m[1] : null;
                    $piece = $m[2];
                }

                $joined .= $encoded ? rawurldecode($piece) : $piece;
            }

            $params[$key] = self::toUtf8($joined, $charset);
        }

        return [$main, $params];
    }

    /**
     * @return list<string>
     */
    private static function multipart(string $body, string $boundary): array
    {
        $parts = [];
        $current = null;
        $open = '--'.$boundary;
        $close = $open.'--';

        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $trimmed = rtrim($line);

            if ($trimmed === $close) {
                if ($current !== null) {
                    $parts[] = implode("\r\n", $current);
                }

                return $parts; // the rest is epilogue
            }

            if ($trimmed === $open) {
                if ($current !== null) {
                    $parts[] = implode("\r\n", $current);
                }

                $current = [];

                continue;
            }

            if ($current !== null) {
                $current[] = $line;
            }
        }

        if ($current !== null) {
            $parts[] = implode("\r\n", $current); // missing closing delimiter
        }

        return $parts;
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    public static function decodeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return trim($value);
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim($decoded !== false ? $decoded : mb_decode_mimeheader($value));
    }

    /**
     * @return list<array{email: string, name: string|null}>
     */
    private static function addresses(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $result = [];

        foreach (self::splitOutsideQuotes($value, ',') as $entry) {
            $entry = trim($entry);

            if (preg_match('/^(.*)<([^>]+)>\s*$/', $entry, $m) === 1) {
                $name = trim(self::decodeHeader(trim($m[1])), " \"'");
                $email = trim($m[2]);
            } else {
                $name = null;
                $email = trim($entry, " <>\"'");
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $result[] = ['email' => mb_strtolower($email), 'name' => $name !== '' ? $name : null];
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function splitOutsideQuotes(string $value, string $separator): array
    {
        $parts = [];
        $current = '';
        $quoted = false;
        $angle = 0;

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '"' && ($i === 0 || $value[$i - 1] !== '\\')) {
                $quoted = ! $quoted;
            } elseif (! $quoted && $char === '<') {
                $angle++;
            } elseif (! $quoted && $char === '>') {
                $angle = max(0, $angle - 1);
            }

            if ($char === $separator && ! $quoted && $angle === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    private static function messageId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/<([^>]+)>/', $value, $m) === 1 ? mb_substr($m[1], 0, 300) : (trim($value) !== '' ? mb_substr(trim($value), 0, 300) : null);
    }

    private static function date(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse(preg_replace('/\s*\([^)]*\)\s*$/', '', $value))->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    public static function toUtf8(string $text, ?string $charset): string
    {
        $charset = strtoupper(trim((string) $charset));

        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);

        return $converted !== false ? $converted : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|li|h[1-6])>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;

        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text) ?? $text);
    }

    private static function cleanFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;

        return mb_substr(trim($name) !== '' ? trim($name) : 'attachment', 0, 200);
    }

    private static function extensionFor(string $type): string
    {
        return match ($type) {
            'application/pdf' => '.pdf',
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'text/calendar' => '.ics',
            default => '.bin',
        };
    }
}
