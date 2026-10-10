<?php

namespace App\Domain\Calendar\Support;

use Illuminate\Support\Carbon;

final readonly class CalendarEvent
{
    public function __construct(
        public string $uid,
        public Carbon $date,
        public string $summary,
        public ?string $description = null,
        public ?string $url = null,
        public ?string $category = null,
    ) {}
}
