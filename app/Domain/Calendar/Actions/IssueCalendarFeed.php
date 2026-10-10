<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Models\CalendarFeed;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the user's calendar link for the current dealer; an earlier link stops working.
 * Returns the address once (only its hash is kept).
 */
class IssueCalendarFeed
{
    /**
     * @return array{0: CalendarFeed, 1: string} feed and the full URL
     */
    public function __invoke(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $this->revoke($user);

            $plain = CalendarFeed::PREFIX.Str::random(40);
            $feed = CalendarFeed::query()->create([
                'user_id' => $user->getKey(),
                'token_prefix' => substr($plain, 0, 12),
                'token_hash' => hash('sha256', $plain),
            ]);

            return [$feed, route('calendar.feed', ['token' => $plain.'.ics'])];
        });
    }

    public function revoke(User $user): int
    {
        return CalendarFeed::query()->where('user_id', $user->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
