<?php

namespace App\Domain\Inbox\Mime;

use Illuminate\Support\Carbon;

/**
 * An e-mail taken apart: addresses, subject, text and HTML body, attachments.
 */
final class ParsedMessage
{
    /**
     * @param  array{email: string, name: string|null}|null  $from
     * @param  list<array{email: string, name: string|null}>  $to
     * @param  list<array{email: string, name: string|null}>  $cc
     * @param  list<Attachment>  $attachments
     */
    public function __construct(
        public ?string $messageId = null,
        public ?string $inReplyTo = null,
        public ?array $from = null,
        public array $to = [],
        public array $cc = [],
        public ?string $subject = null,
        public ?Carbon $date = null,
        public ?string $text = null,
        public ?string $html = null,
        public array $attachments = [],
    ) {}
}
