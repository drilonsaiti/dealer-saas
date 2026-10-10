<?php

namespace App\Http\Controllers;

use App\Domain\Calendar\Models\CalendarFeed;
use App\Domain\Calendar\Support\CalendarEvents;
use App\Domain\Calendar\Support\IcsWriter;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Response;

/**
 * The personal calendar subscription (.ics). The secret link is the only key: it works without
 * login, as long as it is not replaced or switched off, the user is still a member of the
 * dealer and may see vehicles.
 */
class CalendarFeedController
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(string $token): Response
    {
        $token = preg_replace('/\.ics$/', '', $token) ?? $token;

        $feed = str_starts_with($token, CalendarFeed::PREFIX)
            ? $this->context->bypass(fn () => CalendarFeed::query()->withoutGlobalScopes()->with(['tenant', 'user'])->where('token_hash', hash('sha256', $token))->first())
            : null;

        abort_if($feed === null || $feed->revoked_at !== null || $feed->tenant->status !== Tenant::STATUS_ACTIVE, 404);

        $this->context->set($feed->tenant);

        try {
            abort_if($feed->user->roleIn($feed->tenant) === null, 404);

            if ($feed->last_used_at === null || $feed->last_used_at->lt(now()->subMinutes(10))) {
                $feed->forceFill(['last_used_at' => now()])->saveQuietly();
            }

            $locale = $feed->user->locale ?? $feed->tenant->default_locale ?? 'de';
            $events = (new CalendarEvents($feed->tenant, $locale))->for($feed->user);
            $body = IcsWriter::render($feed->tenant->name, $events);
        } finally {
            $this->context->clear();
        }

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="dealer-calendar.ics"',
            'Cache-Control' => 'private, max-age=900',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
