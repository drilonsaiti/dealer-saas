<?php

namespace App\Domain\Integrations\Support;

final readonly class ChannelResult
{
    public function __construct(
        public string $externalId,
        public ?string $url = null,
    ) {}
}
