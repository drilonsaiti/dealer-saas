<?php

namespace App\Domain\Calendar\Support;

use Illuminate\Support\Carbon;

/**
 * iCalendar (RFC 5545) with all-day events: CRLF, escaped text, lines folded at 75 octets.
 */
final class IcsWriter
{
    /**
     * @param  list<CalendarEvent>  $events
     */
    public static function render(string $name, array $events): string
    {
        $stamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Dealer SaaS//Calendar//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text($name),
            'X-WR-TIMEZONE:Europe/Zurich',
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$event->uid;
            $lines[] = 'DTSTAMP:'.$stamp;
            $lines[] = 'DTSTART;VALUE=DATE:'.$event->date->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$event->date->copy()->addDay()->format('Ymd');
            $lines[] = 'SUMMARY:'.self::text($event->summary);
            $lines[] = 'TRANSP:TRANSPARENT';

            if ($event->description !== null) {
                $lines[] = 'DESCRIPTION:'.self::text($event->description);
            }

            if ($event->url !== null) {
                $lines[] = 'URL:'.$event->url;
            }

            if ($event->category !== null) {
                $lines[] = 'CATEGORIES:'.self::text($event->category);
            }

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    private static function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\\,', '\\n', '\\n'], $value);
    }

    /**
     * Lines longer than 75 octets continue on the next line after a space (never inside a UTF-8 character).
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $parts = [];
        $current = '';

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($parts === [] ? 75 : 74)) {
                $parts[] = $current;
                $current = '';
            }

            $current .= $char;
        }

        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
