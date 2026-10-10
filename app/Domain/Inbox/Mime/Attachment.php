<?php

namespace App\Domain\Inbox\Mime;

final readonly class Attachment
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $content,
        public bool $inline = false,
    ) {}
}
